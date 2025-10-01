<?php
namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Models\LogEntry; // Убедитесь, что вы используете правильное имя вашей модели

class DatabaseLogger extends AbstractProcessingHandler
{
    protected function write(LogRecord  $record): void
    {
        try {
            // Проверяем, что соединение с БД установлено
            if (!app()->bound('db')) {
                return;
            }

            $db = app('db');
            if (!$db || !$db->connection() || !$db->connection()->getPdo()) {
                return;
            }

            // Получаем полное сообщение
            $message = $record['message'];
            
            // Если есть исключение в контексте, добавляем его к сообщению
            if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                $exception = $record['context']['exception'];
                $message = $exception->getMessage() . "\nFile: " . $exception->getFile() . 
                          "\nLine: " . $exception->getLine() . "\nTrace:\n" . $exception->getTraceAsString();
            }

            // Преобразуем сообщение в UTF-8
            $message = mb_convert_encoding($message, 'UTF-8', 'auto');

            // Преобразуем контекст в JSON, убедившись, что он также в UTF-8
            $context = json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            // Создаем запись в логе
            LogEntry::create([
                'level' => $record['level_name'],
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Exception $e) {
            // Если не удалось записать в БД, игнорируем ошибку
            return;
        }
    }
}
