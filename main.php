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
    echo "⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ ::error::Invalid model specified. Please use either 'gpt-3.5-turbo', 'gpt-4o-mini', 'gpt-4' or 'gpt-4-32k'." . PHP_EOL;
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

  sendTelegram(
    $newTitle,
    $newDescription,
    $newWarnings,
    $newExamples,
    $committerEmail,
    $committerName,
    $commitTitle,
    $commitChangeStats
  );
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

  echo('----------').PHP_EOL;
  echo('$response').PHP_EOL;
  print_r($response);

  if ($response === false) {
    echo('----------').PHP_EOL;
    echo "::error::Error fetching AI-generated title and description." . PHP_EOL;
    $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');
    $tg_chat_id = getenv('TELEGRAM_CHAT_ID');
    $commit_url = getenv('COMMIT_URL');
    $repo_name = getenv('REPO_NAME');

    $message = "⚡️⚡️⚡️ИИ не доступен⚡️⚡️⚡️\n";
    $message .= "Автор: $committerName ($committerEmail)\n";
    $message .= "Комментарий: <pre><code>$commitTitle</code></pre>\n";
    $message .= "Commit URL: [$commit_url]($commit_url)\n\n";
    $message .= "#коммиты #безИИ";
    if (!empty($repo_name)) {
      $message .= " #" . toHash($committerEmail);
    }
    $message .= " #" . toHash($repo_name) . " #Date_" . date('Y_m_d');

    sendTelegramMessage($tg_chat_id, $message);

    exit(1);
  }

  $complete = json_decode($response, true);
  $output = $complete['choices'][0]['message']['content'];
//  echo('----------').PHP_EOL;
//  echo('$output').PHP_EOL;
//  print_r($output);
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

function sendTelegramMessage(string $chatId, string $message): void
{
  echo('----------').PHP_EOL;
  echo('sendTelegramMessage|$message=').PHP_EOL;
  print_r($message);

  $htmlMessage = markdownToHtml($message);
  echo('----------').PHP_EOL;
  echo('sendTelegramMessage|strlen($htmlMessage)=').PHP_EOL;
  print_r(strlen($htmlMessage));
  echo('sendTelegramMessage|$htmlMessage=').PHP_EOL;
  print_r($htmlMessage);

  $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');

  // Telegram API message sending URL
  $url = "https://api.telegram.org/bot$tg_bot_token/sendMessage";

  // Check if the message is too long
  $maxLength = 3096; // Maximum length for Telegram messages
  $splitMarker = "⚡️⚡️ИИ пример исправлений⚡️⚡️";


  if (strlen($htmlMessage) > $maxLength) {
    // Split message by the custom split marker
    $messageParts = preg_split('/(' . preg_quote($splitMarker, '/') . ')/u', $htmlMessage);
    echo('sendTelegramMessage|count($messageParts)=').PHP_EOL;
    print_r(count($messageParts));
    foreach ($messageParts as $part) {
      // If the part is longer than the max length, further split it
      if (strlen($part) > $maxLength) {
        $subParts = str_split($part, $maxLength);
        foreach ($subParts as $subPart) {
          $data = [
            'chat_id' => $chatId,
            'text' => $subPart,
            'parse_mode' => 'HTML'
          ];

          $options = [
            'http' => [
              'method' => 'POST',
              'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
              'content' => http_build_query($data),
              'ignore_errors' => true, // Чтобы получить ответ в случае ошибки
            ],
          ];

          $context = stream_context_create($options);
          $result = file_get_contents($url, false, $context);

          tgResponseHandler($result, $url, $chatId);

          if ($result === false) {
            echo '⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Ошибка при отправке сообщения в Telegram.';
            exit(1);
          }
        }
      } else {
        // Send message directly if it's within the max length
        $data = [
          'chat_id' => $chatId,
          'text' => $part,
          'parse_mode' => 'HTML'
        ];

        $options = [
          'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'ignore_errors' => true, // Чтобы получить ответ в случае ошибки
          ],
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        tgResponseHandler($result, $url, $chatId);

        if ($result === false) {
          echo '⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Ошибка при отправке сообщения в Telegram.';
          exit(1);
        }
      }
    }
  } else {
    // Send message directly if it's within the max length
    $data = [
      'chat_id' => $chatId,
      'text' => $htmlMessage,
      'parse_mode' => 'HTML'
    ];

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
        'ignore_errors' => true, // Чтобы получить ответ в случае ошибки
      ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    echo('----------').PHP_EOL;
    echo '$result='.$result;

    tgResponseHandler($result, $url, $chatId);

    if ($result === false) {
      echo '⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Ошибка при отправке сообщения в Telegram.';
      exit(1);
    }
  }

  echo('----------').PHP_EOL;
  echo 'Сообщение успешно отправлено в Telegram!';
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
  $message .= "Commit URL: [$commit_url]($commit_url) \n\n";
  $message .= "#коммиты";
  if (!empty($committerEmail)) {
    $message .= " #" . toHash($committerEmail);
  }
  $message .= " #" . toHash($repo_name);
  if (!empty($branch_name)) {
    $message .= " #" . toHash($branch_name);
  }
  $message .= " #Date_" . date('Y_m_d');

  sendTelegramMessage($tg_chat_id, $message);
}

function getCommitChanges(string $commitSha): string
{
  $command = "git diff {$commitSha}~ {$commitSha} | grep -v 'warning'";

  $output = shell_exec($command);

  if ($output === null) {
    echo "⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Error: Could not run git diff." . PHP_EOL;
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
    echo "⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Error: Could not run git diff --shortstat." . PHP_EOL;
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

function markdownToHtml(string $markdown): string
{
  // Заменяем специальные символы на HTML-сущности
  $html = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);

  // Заменяем заголовки
  $html = preg_replace('/^(\d+)\.\s/', '<p>$1.</p>', $html);

  // Заменяем списки
  $html = preg_replace('/^\s*-\s+(.*)$/m', '<li>$1</li>', $html);
  $html = preg_replace('/<\/li>\s*<\/li>/', '</li><li>', $html);  // Fix double </li> issue
  $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html); // Wrap lists in <ul>

  // Преобразуем <ul><li>...</li></ul> в строки с точками
  $html = preg_replace_callback('/<ul>(.*?)<\/ul>/s', function ($matches) {
    // Преобразуем элементы списка в строки с точками
    $listItems = $matches[1];
    $listItems = preg_replace('/<li>(.*?)<\/li>/', "• $1\n", $listItems);
    return $listItems;
  }, $html);

  // Заменяем примеры кода
  $html = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $html);

  // Заменяем выделение жирным
  $html = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $html);

  // Заменяем выделение курсивом
  $html = preg_replace('/\*(.*?)\*/', '<i>$1</i>', $html);

  // Восстанавливаем поддерживаемые Telegram теги из HTML-сущностей
  $html = str_replace(['&lt;b&gt;', '&lt;/b&gt;', '&lt;i&gt;', '&lt;/i&gt;', '&lt;code&gt;', '&lt;/code&gt;', '&lt;pre&gt;', '&lt;/pre&gt;'],
    ['<b>', '</b>', '<i>', '</i>', '<code>', '</code>', '<pre>', '</pre>'], $html);

  // Заменяем неподдерживаемые теги на аналоги
  $html = str_replace(['<p>', '</p>'], ["\n", "\n"], $html);  // <p> -> new line
  $html = str_replace(['<ul>', '</ul>'], ["\n", "\n"], $html);  // <ul> -> new line
  $html = str_replace(['<li>', '</li>'], ["• ", "\n"], $html);  // <li> -> bullet point
  $html = str_replace(['<h1>', '</h1>', '<h2>', '</h2>', '<h3>', '</h3>'], ["\n<b>", "</b>\n", "\n<b>", "</b>\n", "\n<b>", "</b>\n"], $html); // Headers -> bold text
  $html = str_replace(['<strong>', '</strong>'], ['<b>', '</b>'], $html);  // <strong> -> <b>
  $html = str_replace(['<em>', '</em>'], ['<i>', '</i>'], $html);  // <em> -> <i>

  // Заменяем теги <a> на поддерживаемый Telegram формат ссылок
  $html = preg_replace_callback('/<a href=&apos;(.*?)&apos;>(.*?)<\/a>/', function ($matches) {
    $url = $matches[1];
    $text = $matches[2];
    return "[$text]($url)";
  }, $html);

  // Заменяем HTML-сущность &apos; на одиночную кавычку
  $html = str_replace('&apos;', "'", $html);

  // Заменяем теги <a> на поддерживаемый Telegram формат ссылок
  $html = preg_replace_callback('/<a href="(.*?)">(.*?)<\/a>/', function ($matches) {
    $url = $matches[1];
    $text = $matches[2];
    return "[$text]($url)";
  }, $html);

  // Заменяем теги <a> на поддерживаемый Telegram формат ссылок
  $html = preg_replace_callback('/<a href=\'(.*?)\'>(.*?)<\/a>/', function ($matches) {
    $url = $matches[1];
    $text = $matches[2];
    return "[$text]($url)";
  }, $html);

  return $html;
}





function tgResponseHandler(string $result, string $url, string $chatId): void
{
  $result = json_decode($result, true);
  if (!$result['ok']) {
    $errorMessage = "⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Ошибка при отправке сообщения в Telegram:\n";
    $errorMessage .= "Код ошибки: {$result['error_code']}\n";
    $errorMessage .= "Описание ошибки: {$result['description']}\n";

    // Отправляем сообщение об ошибке в Telegram
    $errorData = [
      'chat_id' => $chatId,
      'text' => $errorMessage,
      'parse_mode' => 'HTML'
    ];

    $errorOptions = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($errorData),
        'ignore_errors' => true,
      ],
    ];

    $errorContext = stream_context_create($errorOptions);
    $errorResult = file_get_contents($url, false, $errorContext);

    if ($errorResult === false) {
      echo '⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Ошибка при отправке сообщения об ошибке в Telegram.' . PHP_EOL;
      exit(1);
    }

    $errorResult = json_decode($errorResult, true);
    if (!$errorResult['ok']) {
      echo '⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️ Не удалось отправить сообщение об ошибке в Telegram.' . PHP_EOL;
      exit(1);
    }

    echo 'Сообщение об ошибке успешно отправлено в Telegram!' . PHP_EOL;
    exit(1);
  }
}
