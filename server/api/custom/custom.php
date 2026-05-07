<?php
namespace api\custom {
    use api\api;
    
    class custom extends api {
        private static $db = null;
        
        private static function initDB() {
            if (self::$db === null) {
                try {
                    $dsn = "pgsql:host=localhost;dbname=rbt;port=5432";
                    self::$db = new \PDO($dsn, "rbt", "rbt");
                    self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                } catch (\PDOException $e) {
                    error_log("Connection failed: " . $e->getMessage());
                    self::$db = false;
                }
            }
            return self::$db;
        }
        
        public static function GET($params) {
            // Штатный сценарий платформы: /custom/custom/<methodId>
            // Например, activateApartment для кнопки "Добавить в биллинг".
            if (isset($params["_id"]) && $params["_id"] !== "") {
                $custom = loadBackend("custom");
                $answer = false;
                if ($custom) {
                    $answer = $custom->GET($params);
                }
                return api::ANSWER($answer, ($answer !== false) ? "custom" : false);
            }

            // Кастомный режим ваших методов через query-параметры:
            // /custom/custom?method=getCams&contract=...
            if (!isset($params['method']) || !isset($params['contract'])) {
                return api::ANSWER(false, "Missing required parameters: method and contract");
            }
            
            $method = $params['method'];
            $contract = $params['contract'];
            
            switch ($method) {
                case 'getCams':
                    $result = self::getCameras($contract);
                    break;
                case 'getFlat':
                    $result = self::getFlatInfo($contract);
                    break;
                case 'getEntrances':
                    $result = self::getEntrances($contract);
                    break;
                default:
                    return api::ANSWER(false, "Unknown method: {$method}");
            }
            
            return api::ANSWER($result, $result !== false ? "custom" : false);
        }
        
        private static function getCameras($contract) {
            $db = self::initDB();
            if (!$db) return false;
            
            try {
                $sql = "SELECT cameras.camera_id
                        FROM houses_flats
                        JOIN houses_cameras_flats ON houses_flats.house_flat_id = houses_cameras_flats.house_flat_id
                        JOIN cameras ON houses_cameras_flats.camera_id = cameras.camera_id
                        WHERE houses_flats.contract = :contract";
                
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contract', $contract);
                $stmt->execute();
                
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                error_log("Error in getCameras: " . $e->getMessage());
                return false;
            }
        }
        
        private static function getFlatInfo($contract) {
            $db = self::initDB();
            if (!$db) return false;
            
            try {
                $sql = "SELECT house_flat_id AS flatId, flat, address_house_id AS houseId
                        FROM houses_flats
                        WHERE contract = :contract";
                
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contract', $contract);
                $stmt->execute();
                
                return $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                error_log("Error in getFlatInfo: " . $e->getMessage());
                return false;
            }
        }
        
        private static function getEntrances($contract) {
            $db = self::initDB();
            if (!$db) return false;
            
            try {
                $sql = "SELECT array_agg(DISTINCT he.house_entrance_id) AS entrance_ids
                        FROM houses_flats hf
                        JOIN houses_entrances_flats hef ON hf.house_flat_id = hef.house_flat_id
                        JOIN houses_entrances he ON hef.house_entrance_id = he.house_entrance_id
                        WHERE hf.contract = :contract";
                
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':contract', $contract);
                $stmt->execute();
                
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($result && $result['entrance_ids'] !== null) {
                    // Преобразуем строку формата {1,2,3} в массив PHP
                    $ids = trim($result['entrance_ids'], '{}');
                    $result['entrance_ids'] = $ids ? explode(',', $ids) : [];
                } else {
                    $result = ['entrance_ids' => []];
                }
                
                return $result;
            } catch (\PDOException $e) {
                error_log("Error in getEntrances: " . $e->getMessage());
                return false;
            }
        }
        
        public static function POST($params) {
            $custom = loadBackend("custom");
            $answer = false;
            if ($custom) {
                $answer = $custom->POST($params);
            }
            return api::ANSWER($answer, ($answer !== false) ? "custom" : false);
        }
        
        public static function PUT($params) {
            $custom = loadBackend("custom");
            $answer = false;
            if ($custom) {
                $answer = $custom->PUT($params);
            }
            return api::ANSWER($answer, ($answer !== false) ? "custom" : false);
        }
        
        public static function DELETE($params) {
            $custom = loadBackend("custom");
            $answer = false;
            if ($custom) {
                $answer = $custom->DELETE($params);
            }
            return api::ANSWER($answer, ($answer !== false) ? "custom" : false);
        }
        
        public static function index() {
            $custom = loadBackend("custom");
            if ($custom) {
                return [
                    "GET",
                    "POST",
                    "PUT",
                    "DELETE",
                ];
            } else {
                return [
                    "GET"
                ];
            }
        }
    }
}
