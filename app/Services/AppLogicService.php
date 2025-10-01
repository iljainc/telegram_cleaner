<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Master;
use App\Models\Order;

class AppLogicService
{
    /**
     * Выполняет указанную функцию.
     */
    public function execute($functionName, $arguments)
    {
        // Проверяем, существует ли метод с таким именем
        if (method_exists($this, $functionName)) {
            return $this->{$functionName}($arguments);
        }

        throw new \Exception("Unknown function: {$functionName}");
    }

    /**
     * Пример функции создания заказа.
     */
    private function addOrder($arguments, $uid = false)
    {
        // Проверяем наличие обязательных полей
        if (empty($arguments['description']) || empty($arguments['locations']) || !is_array($arguments['locations'])) {
            Log::error('addOrder: отсутствуют обязательные поля', ['arguments' => $arguments]);
            return false;
        }

        foreach ($arguments['locations'] as $location) {
            if (empty($location['address']) || empty($location['city']) || empty($location['country'])) {
                Log::error('addOrder: некорректные данные локации', ['location' => $location]);
                return false;
            }
        }

        $text = $arguments['description'];

        // Предположим, что мы инициализируем $text заранее
        $textParts[] = $text;

        $uid = $uid ?: auth()->id();

        // Создаём заказ сразу
        $order = Order::create([
            'uid'                      => $uid,
            'text'                     => '',    // временно пустое (или что-то по умолчанию)
        ]);

        // Массив для ID локаций
        $locationIds = [];

        foreach ($arguments['locations'] as $locationData) {
            // Собираем адреса
            $textParts[] = $locationData['address'];

            // Ищем локацию
            $location = Location::findLocation(
                $locationData['city'],
                false,
                $locationData['country']
            );

            if ($location) {
                // Сохраняем ID
                $locationIds[] = $location->id;
            }
        }

        // Привязываем все найденные локации одним вызовом
        $uniqueLocationIds = array_unique($locationIds);
        $order->locations()->attach($uniqueLocationIds);

        // Склеиваем адреса с переводами строки и обновляем поле text
        $order->text = implode("\n", $textParts);
        $order->save();

        return ['order_id' => $order->id];
    }

    private function closeOrder($arguments, $uid = false)
    {
        // Если uid не передан, берём ID текущего авторизованного пользователя
        $uid = $uid ?: auth()->id();

        // Ищем заказ с нужным ID и тем же uid (если нужно ограничить закрытие чужих заказов)
        $order = Order::where('id', $arguments['order_id'])
            ->where('uid', $uid)
            ->firstOrFail();

        // Закрываем заказ (ставим отметку о дате закрытия)
        $order->update(['closed_at' => now()]);

        return ['status' => 'order closed'];
    }

    private function getActiveOrders($arguments, $uid = false)
    {
        // Если uid не передан, берём ID текущего авторизованного пользователя
        $uid = $uid ?: auth()->id();

        // Получаем все заказы текущего пользователя, у которых поле closed_at = NULL
        $orders = Order::whereNull('closed_at')
            ->where('uid', $uid)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get(['id', 'text']);

        // Формируем структуру для ответа
        return [
            'active_orders' => $orders->map(function ($order) {
                return [
                    'id'   => $order->id,
                    'text' => $order->text,
                ];
            })->toArray(),
        ];
    }

    private function closeAllOrders($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Закрываем все заказы, у которых closed_at = NULL
        Order::where('uid', $uid)
            ->whereNull('closed_at')
            ->update(['closed_at' => now()]);

        return ['status' => 'all orders closed'];
    }

    private function addMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Проверяем наличие обязательных полей
        if (empty($arguments['description']) || empty($arguments['country'])) {
            return false;
        }

        // Ищем уже имеющегося мастера
        $master = Master::where('uid', $uid)->first();

        // Если мастера нет — создаём нового
        if (!$master) {
            $master = Master::create([
                'uid'       => $uid,
                'closed_at' => null,
                'text'      => $arguments['description'],
            ]);
        } else {
            // Реактивируем существующего мастера
            $master->closed_at = null;
            $master->text_admin_check = Master::TEXT_ADMIN_CHECK_NEW; // На можерацию
            $master->text_en = ''; // Обнуляем перевод
            $master->text      = $arguments['description'];
            $master->save();
        }

        // Location processing moved to SendMsgToAdmin command
        // AI location extraction will be done there

        return [
            'status'    => 'master added or reactivated',
            'master_id' => $master->id,
        ];
    }


    /**
     * «Закрывает» (деактивирует) текущего мастера у пользователя.
     */
    private function closeMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Ищем активного мастера пользователя
        $master = Master::where('uid', $uid)
            ->firstOrFail();

        // Ставим дату закрытия
        $master->update(['closed_at' => now()]);

        return ['status' => 'master closed'];
    }

    private function getMaster($arguments, $uid = false)
    {
        $uid = $uid ?: auth()->id();

        // Ищем "активного" мастера
        $master = Master::where('uid', $uid)
            ->whereNull('closed_at')
            ->first();

        if (!$master) {
            return [
                'status' => 'You are not registered as a master',
            ];
        }

        return [
            'status'    => 'success',
            'text'      => $master->text,
        ];
    }
}
