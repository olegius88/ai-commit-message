<?php

declare(strict_types=1);

function main(): void
{
  $commitSha = getenv('GITHUB_SHA') ?: '';
  exec('git config --global --add safe.directory /github/workspace');

  $commitTitle = exec('git log -1 --pretty=%s');

  $committerName = exec("git log -1 --pretty=%cn $commitSha");
  $committerEmail = exec("git log -1 --pretty=%ce $commitSha");

  // Добавляем проверку на GitHub коммиты
  if ($committerName === 'GitHub' && $committerEmail === 'noreply@github.com') {
    $authorInfo = getRealAuthorInfo($commitSha);
    $committerName = $authorInfo['name'];
    $committerEmail = $authorInfo['email'];
  }

  $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'; // Default to gpt-4o-mini if no environment variable is set

  if (!in_array($model, ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-32k', 'gpt-4o-mini'])) {
    echo "::error::Invalid model specified. Please use either 'gpt-3.5-turbo', 'gpt-4o-mini', 'gpt-4' or 'gpt-4-32k'." . PHP_EOL;
    exit(1);
  }

  list($newTitle, $newDescription, $newWarnings, $newExamples) = fetchAiGeneratedTitleAndDescription(
    getCommitChanges($commitSha),
    getenv('OPENAI_API_KEY'),
    $committerEmail,
    $committerName,
    $commitTitle
  );

  $commitChangeStats = getCommitChangeStats($commitSha);

  sendTelegram($newTitle, $newDescription, $newWarnings, $newExamples, $committerEmail, $committerName, $commitTitle, $commitChangeStats);
}

main();

function fetchAiGeneratedTitleAndDescription(string $commitChanges, string $openAiApiKey, string $committerEmail,
                                             string $committerName, string $commitTitle): array
{
  $prompt = generatePrompt($commitChanges);

  $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
  if ($model == 'gpt-3.5-turbo') $model = 'gpt-4o-mini';

  $length = $model ? match ($model) {
    'gpt-3.5-turbo' => 4096,  // до 4096 токенов
    'gpt-4' => 8192,          // до 8192 токенов
    'gpt-4-32k' => 32768,     // до 32768 токенов
    'gpt-4o-mini' => 8192,
  } : 4096;

  $input_data = [
    "temperature" => 0.7,
//    "max_tokens" => $length,
    "frequency_penalty" => 0,
    'model' => $model,
    "messages" => [
      [
        'role' => 'user',
        'content' => $prompt
      ],
    ]
  ];

  $response = file_get_contents("https://api.openai.com/v1/chat/completions", false, stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Authorization: Bearer {$openAiApiKey}\r\n" .
        "Content-Type: application/json\r\n",
      'content' => json_encode($input_data),
    ]
  ]));

  echo('$response').PHP_EOL;
  print_r($response);

  if ($response === false) {
    echo "::error::Error fetching AI-generated title and description." . PHP_EOL;
    $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');
    $tg_chat_id = getenv('TELEGRAM_CHAT_ID');
    $commit_url = getenv('COMMIT_URL');
    $repo_name = getenv('REPO_NAME');

    $message = "⚡️⚡️⚡️ИИ не доступен⚡️⚡️⚡️\n";
    $message .= "Автор: $committerName ($committerEmail)\n";
    $message .= "Комментарий: <pre><code>$commitTitle</code></pre>\n";
    $message .= "Commit URL: <a href='$commit_url'>$commit_url</a>\n\n";
    $message .= "#коммиты #безИИ";
    if (!empty($repo_name)) {
      $message .= " #" . toHash($committerEmail);
    }
    $message .= " #" . toHash($repo_name) . " #Date_" . date('Y_m_d');

    $data = [
      'chat_id' => $tg_chat_id,
      'text' => $message,
      'parse_mode' => 'HTML'
    ];

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
      ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents("https://api.telegram.org/bot$tg_bot_token/sendMessage", false, $context);

    if ($result === false) {
      echo 'Ошибка при отправке сообщения в Telegram.';
    } else {
      echo 'Сообщение успешно отправлено в Telegram!';
    }
    exit(1);
  }

  $complete = json_decode($response, true);
  $output = $complete['choices'][0]['message']['content'];
  echo('$output').PHP_EOL;
  print_r($output);
  return extractTitleAndDescription($output);
}

function generatePrompt(string $commitChanges): string
{
  return "Based on the following line-by-line changes in the commit, please create an informative commit title, description, and warnings.
     \nIf you encounter a gross security breach or very bad code, point it out in a rough way and provide detailed examples of how to fix it or examples of the correct option without violations
     \n(take as many lines as you can without violating the limits on the maximum number of tokens in the model).:
     \nCommit changes:
     \n{$commitChanges}
     \nFormulate your answer as follows, be sure to use Russian:
     \nCommit title: [Generated commit title]
     \nCommit description: [Generated commit description]
     \nCommit warnings: [Generated commit warnings, if any]
     \nCommit examples: [Generated examples of correct fixing of the Generated commit warnings, if any]";
}

function extractTitleAndDescription(string $output): array
{
  // Инициализируем переменные
  $title = '';
  $description = '';
  $warnings = '';
  $examples = '';

  // Разделяем входной текст на строки
  $responseLines = explode("\n", $output);

  // Проходим по каждой строке
  foreach ($responseLines as $line) {
    if (str_starts_with($line, 'Commit title: ')) {
      $title = str_replace('Commit title: ', '', $line);
    } elseif (str_starts_with($line, 'Commit description: ')) {
      $description = str_replace('Commit description: ', '', $line);
    } elseif (str_starts_with($line, 'Commit warnings: ')) {
      $warnings = str_replace('Commit warnings: ', '', $line);
    } elseif (str_starts_with($line, 'Commit examples: ')) {
      // Пример может занимать несколько строк, поэтому собираем все строки, начиная с этой
      $examples .= str_replace('Commit examples: ', '', $line) . "\n";
    } elseif ($examples !== '') {
      // Добавляем строки к примерам, если они продолжаются
      $examples .= $line . "\n";
    }
  }

  // Убираем лишние пробелы и переносы строк
  $examples = trim($examples);

  return [$title, $description, $warnings, $examples];
}


function toHash($str): string
{
  return str_replace([':', ';', '-', ',', '.'], '_', $str);
}

function sendTelegram(
  string $newTitle,
  string $newDescription,
  string $newWarnings,
  string $newExamples,
  string $committerEmail,
  string $committerName,
  string $commitTitle,
  string $commitChangeStats
): void
{

  $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');
  $tg_chat_id = getenv('TELEGRAM_CHAT_ID');
  $commit_url = getenv('COMMIT_URL');
  $repo_name = getenv('REPO_NAME');
  $branch_name = getenv('BRANCH_NAME');

  $message = "Автор: $committerName ($committerEmail)\n";
  $message .= "Оригинальный комментарий: <pre><code>$commitTitle</code></pre>\n";
  $message .= "ИИ заголовок: <pre><code>$newTitle</code></pre>\n";
  $message .= "ИИ описание: <pre><code>$newDescription</code></pre>\n";
  $message .= "Статистика изменений: <pre><code>$commitChangeStats</code></pre>\n";
  if (!empty($newWarnings)) {
    switch (str_replace(['.','/'], '', trim($newWarnings))) {
      case 'Не обнаружено грубых нарушений безопасности или очень плохого кода.':
      case 'Никаких предупреждений или безопасности не обнаружено':
      case 'No specific warnings for this commit':
      case 'No warnings for this commit':
      case 'No warnings generated':
      case 'Нет предупреждений':
      case 'Не обнаружено':
      case 'No warnings':
      case 'Отсутствуют':
      case 'None':
      case 'Нет':
      case 'NA':
        break;
      default:
        $message .= "⚡️⚡️ИИ предупреждение⚡️⚡️: <pre><code>$newWarnings</code></pre>\n";
    }
  }
  if (!empty($newExamples)) {
    switch (str_replace(['.','/'], '', trim($newExamples))) {
//      case 'NA':
//        break;
      default:
        $message .= "⚡️⚡️ИИ пример исправлений⚡️⚡️: $newExamples\n";
    }
  }
  $message .= "Commit URL: <a href='$commit_url'>$commit_url</a>\n\n";
  $message .= "#коммиты";
  if (!empty($committerEmail)) {
    $message .= " #" . toHash($committerEmail);
  }
  $message .= " #" . toHash($repo_name);
  if (!empty($branch_name)) {
    $message .= " #" . toHash($branch_name);
  }
  $message .= " #Date_" . date('Y_m_d');

  $data = [
    'chat_id' => $tg_chat_id,
    'text' => $message,
    'parse_mode' => 'HTML'
  ];

  $options = [
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
      'content' => http_build_query($data),
    ],
  ];

  $context = stream_context_create($options);
  $result = file_get_contents("https://api.telegram.org/bot$tg_bot_token/sendMessage", false, $context);

  if ($result === false) {
    echo 'Ошибка при отправке сообщения в Telegram.';
  } else {
    echo 'Сообщение успешно отправлено в Telegram!';
  }
}

function getCommitChanges(string $commitSha): string
{
  $command = "git diff {$commitSha}~ {$commitSha} | grep -v 'warning'";

  $output = shell_exec($command);

  if ($output === null) {
    echo "Error: Could not run git diff." . PHP_EOL;
    exit(1);
  }

  $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
  if ($model == 'gpt-3.5-turbo') $model = 'gpt-4o-mini';

  $length = $model ? match ($model) {
    'gpt-3.5-turbo' => 4096,  // до 4096 токенов
    'gpt-4' => 8192,          // до 8192 токенов
    'gpt-4-32k' => 32768,     // до 32768 токенов
    'gpt-4o-mini' => 8192,
  } : 4096;

  $output = array_slice(explode("\n", $output), 0, $length);
  return implode("\n", $output);
}

function getCommitChangeStats(string $commitSha): string
{
  $command = "git diff --shortstat {$commitSha}~ {$commitSha}";

  $output = shell_exec($command);

  if ($output === null) {
    echo "Error: Could not run git diff --shortstat." . PHP_EOL;
    exit(1);
  }

  return trim($output);
}

function getRealAuthorInfo(string $commitSha): array
{
  $command = "git log $commitSha --pretty=format:'%an <%ae>'";
  $output = shell_exec($command);

  if ($output === null) {
    echo "Error: Could not retrieve author info." . PHP_EOL;
    exit(1);
  }

  $lines = explode("\n", $output);
  foreach ($lines as $line) {
    if (!str_contains($line, 'GitHub') && !str_contains($line, 'noreply@github.com')) {
      list($name, $email) = explode('<', $line);
      return ['name' => trim($name), 'email' => trim($email, '> ')];
    }
  }

  return ['name' => 'Unknown', 'email' => 'unknown@example.com'];
}
