<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require 'vendor/autoload.php';

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
    $model,
  );

//  updateLastCommitMessage($newTitle, $newDescription, $committerEmail, $committerName);
  sendTelegram($newTitle, $newDescription, $committerEmail, $committerName);
}

main();

function fetchAiGeneratedTitleAndDescription(string $commitChanges, string $openAiApiKey, string $model): array
{
  $prompt = generatePrompt($commitChanges);

  $input_data = [
    "temperature" => 0.7,
    "max_tokens" => 300,
    "frequency_penalty" => 0,
    'model' => $model,
    "messages" => [
      [
        'role' => 'user',
        'content' => $prompt
      ],
    ]
  ];

  try {
    $client = new Client([
      'base_uri' => 'https://api.openai.com',
      'headers' => [
        'Authorization' => 'Bearer ' . $openAiApiKey,
        'Content-Type' => 'application/json'
      ]
    ]);

    $response = $client->post('/v1/chat/completions', [
      'json' => $input_data
    ]);

    $complete = json_decode($response->getBody()->getContents(), true);
    $output = $complete['choices'][0]['message']['content'];

    return extractTitleAndDescription($output);

  } catch (GuzzleException $e) {
    echo "::error::Error fetching AI-generated title and description: " . $e->getMessage() . PHP_EOL;
    exit(1);
  }
}

function generatePrompt(string $commitChanges): string
{
  return "Based on the following line-by-line changes in a commit, please generate an informative commit title and description
     \n(max two or three lines of description to not exceed the model max token limitation):
     \nCommit changes:
     \n{$commitChanges}
     \nFormat your response as follows:
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

function sendTelegram(
  string $newTitle,
  string $newDescription,
  string $committerEmail,
  string $committerName
): void
{
  $newTitle = escapeshellarg($newTitle);
  $newDescription = escapeshellarg($newDescription);

  $tg_bot_token = getenv('TELEGRAM_BOT_TOKEN');
  $tg_chat_id = getenv('TELEGRAM_CHAT_ID');


  // Создаем экземпляр клиента GuzzleHttp
  $client = new Client();

// Данные для отправки сообщения
  $telegramBotToken = 'YOUR_TELEGRAM_BOT_TOKEN';
  $telegramChatId = 'YOUR_TELEGRAM_CHAT_ID';
  $message = $newTitle.'|'.$newTitle;

// Отправляем запрос к API Telegram
  try {
    $response = $client->post("https://api.telegram.org/bot{$telegramBotToken}/sendMessage", [
      'json' => [
        'chat_id' => $telegramChatId,
        'text' => $message,
      ],
    ]);

    // Получаем ответ от API Telegram
    $statusCode = $response->getStatusCode();
    $responseData = json_decode($response->getBody()->getContents(), true);

    if ($statusCode === 200 && $responseData['ok'] === true) {
      echo 'Сообщение успешно отправлено в Telegram!';
    } else {
      echo 'Ошибка при отправке сообщения в Telegram.';
    }
  } catch (GuzzleException $e) {
    echo 'Произошла ошибка при выполнении запроса: ' . $e->getMessage();
    exit(1);
  }
}

function updateLastCommitMessage(
  string $newTitle,
  string $newDescription,
  string $committerEmail,
  string $committerName
): void
{
  configureGitCommitter($committerEmail, $committerName);

  $newTitle = escapeshellarg($newTitle);
  $newDescription = escapeshellarg($newDescription);

//  exec("git reset --soft HEAD~1");
//  exec("git commit -m {$newTitle} -m {$newDescription}");
//  exec("git push origin --force");

  // Определяем идентификатор последнего коммита
  $commitSha = trim(shell_exec("git rev-parse HEAD"));

// Получаем текущий комментарий к коммиту
  $currentComment = trim(shell_exec("git log -1 --pretty=%B"));

  // Проверяем, существует ли ветка "AI commit message"
  $branchExists = executeCommand("git rev-parse --verify AI\ commit\ message");
  if ($branchExists) {
    echo "Branch 'AI commit message' already exists." . PHP_EOL;
  } else {
    // Создаем новую ветку "AI commit message" и переключаемся на нее
    $branchCreated = executeCommand("git checkout -b \"AI commit message\"");
    if ($branchCreated) {
      echo "New branch 'AI commit message' created." . PHP_EOL;
    } else {
      echo "Failed to create branch 'AI commit message'." . PHP_EOL;
      exit(1);
    }
  }


// Изменяем комментарий к коммиту
  $newComment = "New AI-generated commit message";

// Применяем изменения к последнему коммиту с новым комментарием
  $commitAmended = executeCommand("git commit --amend -m \"$newComment\"");

  if ($commitAmended) {
    echo "Commit message amended successfully.\n";
  } else {
    echo "Failed to amend commit message.\n";
    exit(1);
  }

// Пушим изменения в ветку "AI commit message"
  $pushed = executeCommand("git push -f origin \"AI commit message\"");

  if ($pushed) {
    echo "Changes pushed to branch 'AI commit message'.\n";
  } else {
    echo "Failed to push changes to branch 'AI commit message'.\n";
    exit(1);
  }

  unsetGitCommitterConfiguration();
}

function configureGitCommitter(string $committerEmail, string $committerName): void
{
  exec("git config user.email '{$committerEmail}'");
  exec("git config user.name '{$committerName}'");
}

function unsetGitCommitterConfiguration(): void
{
  exec("git config --unset user.email");
  exec("git config --unset user.name");
}

function getCommitChanges(string $commitSha): string
{
  $command = "git diff {$commitSha}~ {$commitSha} | grep -v 'warning'";

  exec($command, $output, $return_var);

  if ($return_var == 0) {
    $length = getenv('OPENAI_MODEL') ? match (getenv('OPENAI_MODEL')) {
      'gpt-3.5-turbo' => 400,
      'gpt-4' => 800,
      'gpt-4-32k' => 3200,
    } : 400;

    $output = array_slice($output, 0, $length);
    return implode("\n", $output);
  } else {
    echo "Error: Could not run git diff. Return code: " . $return_var;
    exit(1);
  }
}

function executeCommand($command)
{
  $output = null;
  $returnValue = null;
  exec($command, $output, $returnValue);
  return $returnValue === 0;
}

