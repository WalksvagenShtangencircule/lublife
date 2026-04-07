<?php

    /**
     * backends files namespace
     */

    namespace backends\files {

        use backends\backend;

        /**
         * file storage backend
         */

        abstract class files extends backend {

            /**
             * add file to storage
             *
             * $meta["expire"] (optional) expire filetime (unix timestamp)
             *
             * @param string $realFileName
             * @param $stream
             * @param array $metadata
             * @return string uuid
             */

            abstract public function addFile($realFileName, $stream, $metadata = []);

            /**
             * get file from storage
             *
             * @param $uuid
             * @return object stream, fileInfo
             */

            abstract public function getFile($uuid);

            /**
             * @param $uuid
             * @return mixed
             */

            abstract public function getFileStream($uuid);

            /**
             * @param $uuid
             * @return mixed
             */

            abstract public function getFileInfo($uuid);

            /**
             * @param $uuid
             * @param $metadata
             * @return mixed
             */

            abstract public function setFileMetadata($uuid, $metadata);

            /**
             * @param $uuid
             * @return mixed
             */

            abstract public function getFileMetadata($uuid);

            /**
             * @param $query
             * @param $skip
             * @param $limit
             *
             * @return mixed
             */

            abstract public function searchFiles($query, $skip = 0, $limit = 1024);

            /**
             * delete file
             *
             * @param $uuid
             * @return boolean
             */

            abstract public function deleteFile($uuid);

            /**
             * delete files
             *
             * @param mixed
             * @return boolean
             */

            abstract public function deleteFiles($query);

            /**
             * @param $uuid
             * @return mixed
             */

            public function toGUIDv4($uuid) {
                $uuid = "10001000" . $uuid;

                return substr($uuid,  0,  8) . "-" . substr($uuid,  8,  4) . "-" . substr($uuid, 12,  4) . "-" . substr($uuid, 16,  4) . "-" . substr($uuid, 20, 12);
            }

            /**
             * @param $guidv4
             * @return mixed
             */

            public function fromGUIDv4($guidv4) {
                return str_replace("-", "", substr($guidv4, 8));
            }

            /**
             * Идентификатор кадра plog из API/ClickHouse/URL: 36 символов с дефисами, 32 hex без дефисов или 24 hex ObjectId.
             *
             * @param string $raw
             * @return string внутренний id для getFile (24 hex) или пустая строка
             */
            public function plogImageIdToStorageId(string $raw): string {
                $raw = trim($raw);
                if ($raw === "") {
                    return "";
                }
                if (preg_match("/^[0-9a-fA-F]{24}$/", $raw)) {
                    return strtolower($raw);
                }
                $g = $raw;
                if (preg_match("/^[0-9a-fA-F]{32}$/", $g)) {
                    $g = strtolower($g);
                    $g = substr($g, 0, 8) . "-" . substr($g, 8, 4) . "-" . substr($g, 12, 4) . "-" . substr($g, 16, 4) . "-" . substr($g, 20, 12);
                }
                return strtolower($this->fromGUIDv4($g));
            }

            /**
             * @param $contents
             * @return false|resource
             */

            public function contentsToStream($contents) {
                $fd = fopen("php://temp", "w+");

                fwrite($fd, $contents, strlen($contents));
                fseek($fd, 0);

                return $fd;
            }

            /**
             * @param $fd
             * @return false|string
             */

            public function streamToContents($fd) {
                fseek($fd, 0);

                return stream_get_contents($fd);
            }
        }
    }
