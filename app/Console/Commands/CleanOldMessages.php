<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramDanogService;
use Carbon\Carbon;

class CleanOldMessages extends Command
{
    protected $signature = 'clean';
    protected $description = 'Удаляет старые сообщения из определенных подтем Telegram';

    /*
    // Подтемы для очистки (по ID)
    protected $topicsToClean = [
        546074, // Отдам/обменяю (в сентябре)
        546069, // Продам (в сентябре) все остальное
        546065, // Продам (в сентябре) одежду, обувь, аксессуары
        546079, // Детям и мамам (в сентябре)
        546060, // Ищу (в сентябре)
    ];

    protected $rootGroupId = -1001910727730;
    
    protected $topicsToClean = [
        546069, // | Карта сокровищ. Хайфа. Крайоты. | Утилизация отходов  
    ];
    */
    
    protected $rootGroupId = -1002058410679;
    
    protected $topicsToClean = [
        6, // | Карта сокровищ. Хайфа. Крайоты. | Утилизация отходов  
    ];

    public function handle()
    {
        $days = 320;
        $this->info("Начинаю очистку сообщений старше {$days} дней");

        try {
            $telegramService = new TelegramDanogService(null, null, $this);
            $this->info("TelegramDanogService инициализирован");

            $cleanedTopics = 0;
            $deletedMessages = 0;

            foreach ($this->topicsToClean as $topicId) {
                $this->info("Очистка подтемы ID: {$topicId}");
                
                $deleted = $this->cleanTopicMessages($telegramService, $topicId, $days);
                $deletedMessages += $deleted;
                $cleanedTopics++;
                
                $this->info("Удалено сообщений: {$deleted}");
                
                // Пауза между подтемами чтобы не нагружать API
                sleep(2);
            }

            $this->info("Очистка завершена!");
            $this->info("Обработано подтем: {$cleanedTopics}");
            $this->info("Всего удалено сообщений: {$deletedMessages}");

        } catch (\Exception $e) {
            $this->error("Ошибка: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function cleanTopicMessages($telegramService, $topicId, $days)
    {
        $deletedCount = 0;
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Удаляю сообщения старше: " . $cutoffDate->format('Y-m-d H:i:s'));

        try {
            // Получаем сообщения из подтемы
            $this->info("Запрашиваем сообщения из подтемы {$topicId} за последние {$days} дней...");
            $messages = $telegramService->getTopicMessages($this->rootGroupId, $topicId, $days);
            
            // Проверяем, что метод вернул массив
            if ($messages === null) {
                $this->warn("Метод getTopicMessages вернул null для подтемы {$topicId}");
                return 0;
            }
            
            if (!is_array($messages)) {
                $this->warn("Метод getTopicMessages вернул не массив для подтемы {$topicId}: " . gettype($messages));
                return 0;
            }
            
            $this->info("Загружено сообщений с сервера: " . count($messages));
            
            // Сортируем сообщения по ID от меньшего к большему
            usort($messages, function($a, $b) {
                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });
            $this->info("Сообщения отсортированы по ID");
            
            foreach ($messages as $index => $message) {
                // Проверяем дату сообщения
                $messageDate = Carbon::createFromTimestamp($message['date']);
                
                if ($messageDate->lt($cutoffDate)) {
                    // Проверяем, не закреплено ли сообщение
                    if (!($message['pinned'] ?? false)) {
                        try {
                            $result = $telegramService->deleteMessage($this->rootGroupId, $message['id']);
                            $daysAgo = round($messageDate->diffInDays(Carbon::now()), 2);
                            
                            // Проверяем результат удаления
                            $messageNum = $index + 1;
                            $this->info("#{$messageNum} Обработка сообщения ID: {$message['id']} от {$messageDate->format('Y-m-d H:i:s')} ({$daysAgo} дней назад)");
                            
                            if ($result && isset($result['pts_count']) && $result['pts_count'] > 0) {
                                $deletedCount++;
                                $this->line("✓ Удалено сообщение ID: {$message['id']} от {$messageDate->format('Y-m-d H:i:s')} ({$daysAgo} дней назад)");
                            } else {
                                $this->warn("✗ Сообщение {$message['id']} не удалено (возможно уже удалено или нет прав)");
                            }
                            
                            // Пауза между удалениями
                            usleep(500000); // 0.5 секунды
                        } catch (\Exception $e) {
                            $this->warn("✗ Не удалось удалить сообщение {$message['id']}: " . $e->getMessage());
                        }
                    } else {
                        $this->info("Пропущено закрепленное сообщение ID: {$message['id']}");
                    }
                } else {
                    $this->line("Пропущено сообщение ID: {$message['id']} от {$messageDate->format('Y-m-d H:i:s')}");
                }
            }
        } catch (\Exception $e) {
            $this->error("Ошибка при получении сообщений из подтемы {$topicId}: " . $e->getMessage());
        }

        return $deletedCount;
    }
}
