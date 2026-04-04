<?php

    function clearCache($uid) {
        global $redis, $config;

        $keys = [];

        $n = 0;

        if ($uid === true) {
            $keys = $redis->keys("CACHE:*");
        } else {
            if (checkInt($uid)) {
                $front = $redis->keys("CACHE:FRONT:*:$uid");
                /* allowedMethods: ключ вида CACHE:AUTHORIZATION:ALLOWED:<uid>:<ctx> */
                $auth1 = $redis->keys("CACHE:AUTHORIZATION:*:$uid");
                $auth2 = $redis->keys("CACHE:AUTHORIZATION:ALLOWED:$uid:*");
                $keys = array_merge(
                    is_array($front) ? $front : [],
                    is_array($auth1) ? $auth1 : [],
                    is_array($auth2) ? $auth2 : []
                );
                $keys = array_values(array_unique($keys));
            }
        }

        foreach ($keys as $key) {
            $redis->del($key);
            $n++;
        }

        return $n;
    }
