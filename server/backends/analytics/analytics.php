<?php

    /**
     * backends analytics namespace
     */

    namespace backends\analytics {

        use backends\backend;

        abstract class analytics extends backend {

            abstract public function getStats(int $days, ?int $houseId);

            abstract public function getEvents(array $opts);

            /**
             * URL mp4-фрагмента архива DVR вокруг события plog (по camera_id в domophone).
             *
             * @return array{url: string, start: int, finish: int}|false
             */
            abstract public function getDvrArchiveVideoUrlForEvent(int $houseId, string $eventUuid);

            /**
             * Превью строки списка: кадр с DVR (середина окна date±half) при успешной загрузке изображения,
             * иначе кадр plog из GridFS; плюс признак наличия mp4 для отдельного открытия.
             *
             * @return array{preview: ?array{contentType: string, base64: string}, previewSource: string, hasVideo: bool}|null null — событие не найдено / нет доступа
             */
            abstract public function getEventMediaPreview(int $houseId, string $eventUuid);
        }
    }

