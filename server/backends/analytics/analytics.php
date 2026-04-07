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
        }
    }

