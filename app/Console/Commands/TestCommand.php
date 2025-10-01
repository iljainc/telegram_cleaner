<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use Illuminate\Console\Command;
use App\Services\TelegramDanogService;
use Illuminate\Support\Facades\Log;

class TestCommand extends Command
{
    protected $signature = 'info';
    protected $description = 'Получить и вывести список всех групп Telegram';

    public function handle()
    {
        $this->line("TestCommand");
        //$this->analyzeGroups();
        
        // Получаем данные из конфига вместо БД
        $apiId = config('telegram.api_id');
        $apiHash = config('telegram.api_hash');

        if (!$apiId || !$apiHash) {
            $this->error("Не найдены api_id или api_hash в конфиге telegram.php");
            return 1;
        }

        $this->info("API ID: " . $apiId);
        $this->info("API Hash: " . substr($apiHash, 0, 8) . "...");
        $this->info("Инициализация TelegramDanogService...");
        
        try {
            $telegramService = new TelegramDanogService($apiId, $apiHash, $this);
            $this->info("TelegramDanogService успешно инициализирован");
        } catch (\Exception $e) {
            $this->error("Ошибка инициализации TelegramDanogService: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }

        $this->info("Получение списка групп...");
        $groups = $telegramService->getAllGroups();
        $this->info("Получено групп: " . count($groups));

            if (empty($groups)) {
                $this->info("Группы не найдены.");
                return 0;
            }

            $tableData = [];
            foreach ($groups as $group) {
                $tableData[] = [
                    'ID Группы' => $group['id'],
                    'Название' => $group['title'],
                    'Access Hash' => $group['access_hash'] ?? 'Нет хэша',  // Выводим хэш доступа
                    'top_msg_id' => $group['top_msg_id'] ?? '-',
                ];
            }

            $this->table(
                ['ID Группы', 'Название', 'Access Hash', 'top_msg_id'],  // Добавляем top_msg_id в заголовки
                $tableData
            );

        $this->info("Всего найдено групп: " . count($groups));
        return 0;
    }

}
