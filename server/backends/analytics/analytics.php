<?php

    /**
     * backends analytics namespace
     */

    namespace backends\analytics {

        use backends\backend;

        abstract class analytics extends backend {

            abstract public function getStats(int $days, ?int $houseId);

            abstract public function getEvents(array $opts);
        }
    }

