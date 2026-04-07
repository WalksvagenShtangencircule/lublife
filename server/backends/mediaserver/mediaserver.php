<?php

    /**
     * backends mediaserver — управление потоками Flussonic и журнал действий.
     */

    namespace backends\mediaserver {

        use backends\backend;

        abstract class mediaserver extends backend {

            /**
             * Сводка потоков Flussonic + привязка к камерам (имя потока = первый сегмент пути dvrStream).
             *
             * @return array{serverTitle?:string,apiError?:string,streams:array}|false
             */
            abstract public function getStreamsOverview();

            /**
             * Создать/обновить поток (PUT на Flussonic).
             */
            abstract public function upsertStream(string $name, array $body = []): array;

            /**
             * Удалить поток на Flussonic.
             */
            abstract public function deleteStream(string $name): array;

            /**
             * Записать в камеру поля stream (HLS) и dvrStream (embed/архив).
             */
            abstract public function applyUrlsToCamera(int $cameraId, string $hlsUrl, string $embedUrl): bool;

            /**
             * Журнал действий (новые записи сверху).
             *
             * @return array{array{id:int,createdAt:int,login:string,action:string,streamName:?string,cameraId:?int,details:array|string}}
             */
            abstract public function getAuditLog(int $limit = 200, int $offset = 0);
        }
    }
