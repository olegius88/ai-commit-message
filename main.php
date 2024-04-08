<?php

declare(strict_types=1);

function main(): void
{
  $commitSha = getenv('GITHUB_SHA') ?: '';
  exec('git config --global --add safe.directory /github/workspace');

  $commitTitle = exec('git log -1 --pretty=%s');

  $committerName = exec("git log -1 --pretty=%cn $commitSha");
  $committerEmail = exec("git log -1 --pretty=%ce $commitSha");

  $model = getenv('OPENAI_MODEL') ?: 'gpt-3.5-turbo'; // Default to gpt-3.5-turbo if no environment variable is set

  if (!in_array($model, ['gpt-4', 'gpt-4-32k', 'gpt-3.5-turbo'])) {
    echo "::error::Invalid model specified. Please use either gpt-3.5-turbo', 'gpt-4' or 'gpt-4-32k'." .
      PHP_EOL;
    exit(1);
  }

  list($newTitle, $newDescription) = fetchAiGeneratedTitleAndDescription(
    getCommitChanges($commitSha),
    getenv('OPENAI_API_KEY'),
  );

  sendTelegram($newTitle, $newDescription, $committerEmail, $committerName, $commitTitle);
}

main();

function fetchAiGeneratedTitleAndDescription(string $commitChanges, string $openAiApiKey): array
{
  $prompt = generatePrompt($commitChanges);

  $model = getenv('OPENAI_MODEL') ?: 'gpt-3.5-turbo';

  $length = getenv('OPENAI_MODEL') ? match (getenv('OPENAI_MODEL')) {
    'gpt-3.5-turbo' => 400,
    'gpt-4' => 800,
    'gpt-4-32k' => 3200,
  } : 400;

  $input_data = [
    "temperature" => 0.7,
    "max_tokens" => $length,
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

  if ($response === false) {
    echo "::error::Error fetching AI-generated title and description." . PHP_EOL;
    exit(1);
  }

  $complete = json_decode($response, true);
  $output = $complete['choices'][0]['message']['content'];

  return extractTitleAndDescription($output);
}

function generatePrompt(string $commitChanges): string
{
  return "Based on the following line-by-line changes in a commit, please generate an informative commit title and description
     \n(max two or three lines of description to not exceed the model max token limitation):
     \nCommit changes:
     \n{$commitChanges}
     \nFormat your response as follows in Russian:
     \nCommit title: [Generated commit title]
     \nCommit description: [Generated commit description]";
}

function extractTitleAndDescription(string $output): array
{
  $title = '';
  $description = '';
  $responseLines = explode("\n", $output);
  foreach ($responseLines as $line) {
    if (str_starts_with($line, 'Commit title: ')) {
      $title = str_replace('Commit title: ', '', $line);
    } elseif (str_starts_with($line, 'Commit description: ')) {
      $description = str_replace('Commit description: ', '', $line);
    }
  }

  return [$title, $description];
}

function toHash($str): string
{
  return str_replace([':', ';', '-', ',', '.'], '_', $str);
}


function sendTelegram(
  string $newTitle,
  string $newDescription,
  string $committerEmail,
  string $committerName,
  string $commitTitle
): void
{

  $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');
  $tg_chat_id = getenv('TELEGRAM_CHAT_ID');
  $commit_url = getenv('COMMIT_URL');
  $repo_name = getenv('REPO_NAME');

  $message = "Автор: $committerName ($committerEmail)";
  $message .= "\nОригинальный комментарий: $commitTitle";
  $message .= "\nИИ заголовок: $newTitle";
  $message .= "\nИИ описание: $newDescription";
  $message .= "\nCommit URL: $commit_url";
  $message .= "\n";
  $message .= "\n#коммиты #" . toHash($committerEmail);
  if (!empty($repo_name)) {
    $message .= " #" . toHash($repo_name);
  }
  $message .= " #Date_" . date('Y_m_d');

  $data = [
    'chat_id' => $tg_chat_id,
    'text' => $message,
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

  $length = getenv('OPENAI_MODEL') ? match (getenv('OPENAI_MODEL')) {
    'gpt-3.5-turbo' => 400,
    'gpt-4' => 800,
    'gpt-4-32k' => 3200,
  } : 400;

  $output = array_slice(explode("\n", $output), 0, $length);
  return implode("\n", $output);
}
