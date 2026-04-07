<?php

    /**
     * backends mediaserver — управление потоками Flussonic и журнал действий.
     */

    namespace backends\mediaserver {

        use backends\backend;

        abstract class mediaserver extends backend {

            /**
             * Сводка: камеры с ext.mediaserverStreamName (или flussonicStreamName), иначе для старых карточек — имя из URL DVR; статус на Flussonic.
             *
             * @return array{serverTitle?:string,apiError?:string,streams:array}|false
             */
            abstract public function getStreamsOverview();

            /**
             * После сохранения камеры: PUT потока на Flussonic по ext.mediaserverStreamName (inputs = RTSP), при смене имени — удалить старый поток; затем HLS в dvrStream, embed в ext.
             *
             * @return array{cameraId?:int,streamName?:string,flussonic?:array,ok:bool,error?:string}
             */
            abstract public function publishStreamForCamera(int $cameraId): array;

            /**
             * Обновить в БД RTSP и/или срок DVR, затем синхронизировать с Flussonic (как publishStreamForCamera).
             *
             * @param array{stream?:string,mediaserverStreamName?:string,dvrRetentionDays?:int} $updates
             * @return array{cameraId?:int,streamName?:string,flussonic?:array,ok:bool,error?:string}
             */
            abstract public function updateCameraStreamSettings(int $cameraId, array $updates): array;

            /**
             * Создать/обновить поток (PUT на Flussonic).
             */
            abstract public function upsertStream(string $name, array $body = [], bool $writeAudit = true): array;

            /**
             * Удалить поток на Flussonic.
             */
            abstract public function deleteStream(string $name): array;

            /**
             * Удалить поток на Flussonic (404 — потока уже нет) и камеру в БД; streamName должен соответствовать карточке камеры.
             */
            abstract public function deleteStreamAndCamera(string $streamName, int $cameraId): array;

            /**
             * Записать HLS в dvrStream, embed в ext; stream (RTSP) сохранить.
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
