<?php
    
    /**
     * Created by PhpStorm.
     * User: Floris
     * Date: 23-1-2018
     * Time: 09:16
     */
    
    namespace Quellabs\ObjectQuel\CachePool;
    
    class Item {
        const cacheNone = 0x00;
        const cacheHit = 0x01;
        
        private $key;
        private $data;
        private $state;
        private $oldExpire;
        private $expire;
        private $delta;
		
		/**
		 * Item constructor
		 * @param string $key
		 * @param string|array|null $data
		 * @param int $state
		 * @param \DateTime|null $oldExpire
		 * @param \DateTime|null $expire
		 * @param int $delta
		 */
        public function __construct(string $key, $data=null, int $state = self::cacheNone, ?\DateTime $oldExpire = null, ?\DateTime $expire = null, int $delta = 0) {
            $this->key = $key;
            $this->data = $data;
            $this->state = $state;
            $this->oldExpire = $oldExpire;
            $this->expire = $expire;
            $this->delta = $delta;
        }
        
        /**
         * Returns the cached data
         * @return string
         */
        public function getKey(): string {
            return $this->key;
        }
        
        /**
         * Returns the cached data
         * @return mixed
         */
        public function get() {
            return $this->data;
        }
        
        /**
         * Sets/updates cached data
         * @return mixed
         */
        public function set($data) {
            $this->data = $data;
        }
        
        /**
         * Returns the old expire date (e.g. the one obtained from the database)
         * @return \DateTime|null
         */
        public function getOldExpire(): ?\DateTime {
            return $this->oldExpire;
        }
		
        /**
         * Returns the new expire date (e.g. the one set in the callable)
         * @return \DateTime|null
         */
        public function getExpire(): ?\DateTime {
            return $this->expire;
        }
        
        /**
         * Sets the expire date
         * @param mixed $expire
         */
        public function expiresAfter($expire) {
            if ($expire instanceof \DateInterval) {
                $this->expire = new \DateTime();
                $this->expire->add($expire);
            } elseif ($expire instanceof \DateTime) {
                $this->expire = $expire;
            } elseif (is_numeric($expire)) {
                if ($expire < 0) {
                    $this->expire = \DateTime::createFromFormat('Y-m-d', '1990-01-01');
                } else {
                    $expireInMs = round($expire * 1000);
                    $this->expire = new \DateTime();
                    $this->expire->modify("+{$expireInMs} ms");
                }
            } elseif (is_string($expire)) {
                $this->expire = new \DateTime();
                $this->expire->modify($expire);
            } elseif (is_null($expire)) {
                $this->expire = null;
            }
        }
        
        /**
         * Returns true if the cache was hit, false if not
         * @return bool
         */
        public function isHit(): bool {
            if ($this->oldExpire !== null) {
                $dateNow = new \DateTime();
                $expired = $dateNow > $this->oldExpire;
            } else {
                $expired = false;
            }
            
            return ($this->state & self::cacheHit) && !$expired;
        }
        
        /**
         * Returns the delta (the time it takes to regenerate the cache)
         * @return int
         */
        public function getDelta(): int {
            return $this->delta;
        }
        
        /**
         * Sets the delta (the time it takes to regenerate the cache)
         * @param int $delta
         * @return void
         */
        public function setDelta(int $delta) {
            $this->delta = $delta;
        }
    }
    
    class CachePool {
        
        private $db;
        
        /**
         * CachePool constructor.
         * @param \Quellabs\ObjectQuel\EntityManager\databaseAdapter $db
         */
        public function __construct(\Quellabs\ObjectQuel\EntityManager\databaseAdapter $db) {
            $this->db = $db;
        }
    
        /**
         * Sets a cache value
         * @param Item $item
         * @return bool
         * @throws \Exception
         */
        protected function save(Item $item): bool {
            if (is_array($item->get())) {
                $valueRes = json_encode($item->get());
            } else {
                $valueRes = $item->get();
            }
    
            if ($item->getExpire() !== null) {
                $expireDateRes = $item->getExpire()->format("Y-m-d H:i:s");
            } else {
                $expireDateRes = null;
            }
            
            $parameters = [
                'resource_type' => is_array($item->get()),
                'value'         => $valueRes,
                'external'      => 0,
                'date'          => $expireDateRes,
                'processing'    => 0,
                'delta'         => $item->getDelta(),
                'type'          => '',
                'key'           => $item->getKey(),
            ];
        
            if ($this->hasItem($item->getKey())) {
                $this->db->Execute("
                    UPDATE `st_cache` SET
                        `resource_type`=:resource_type,
                        `value`=:value,
                        `external`=:external,
                        `date`=:date,
                        `processing`=:processing,
                        `delta`=:delta
                    WHERE `type`=:type AND `key`=:key
                ", $parameters);
            } else {
                $this->db->Execute("
                    INSERT INTO `st_cache` SET
                        `type`=:type,
                        `key`=:key,
                        `resource_type`=:resource_type,
                        `value`=:value,
                        `external`=:external,
                        `date`=:date,
                        `processing`=:processing,
                        `delta`=:delta
                ", $parameters);
            }
        
            return true;
        }
    
        /**
         * Returns true if the key exists, false otherwise
         * @param string $key
         * @return bool
         */
        public function hasItem(string $key): bool {
            if (($rs = $this->db->Execute("
                SELECT
                    NULL
                FROM `st_cache`
                WHERE `type`='' AND
                      `key`=:key
                LIMIT 1
            ", [
                'key' => $key
            ]))) {
                return ($this->db->RecordCount($rs) > 0);
            }
            
            return false;
        }
    
        /**
         * Get a value from the cache
         * @param string $key
         * @param \Closure|null $default the value to return or closure function to call on a cache miss
         * @param float $beta used for Probabilistic early expiration
         * @return Item
         * @throws \Exception
         */
        public function getItem(string $key, ?\Closure $default = null, float $beta = 1): Item {
            if (($rs = $this->db->Execute("
                SELECT
                    `id`,
                    `resource_type`,
                    `value`,
                    `date`,
                    `processing`,
                    `delta`
                FROM `st_cache`
                WHERE `type`=:type AND
                      `key`=:key
            ", [
                'key'  => $key,
                'type' => '',
            ]))) {
                if ($this->db->RecordCount($rs) > 0) {
                    $row = $this->db->FetchRow($rs);
                    
                    // convert expire date to Datetime
                    if ($row["date"] !== null) {
                        $expireDate = \DateTime::createFromFormat("Y-m-d H:i:s", $row["date"]);
                    } else {
                        $expireDate = null;
                    }
                    
                    // fetch the contents from db
                    if ($row["resource_type"] == 1) {
                        $returnValue = json_decode($row["value"], true);
                    } else {
                        $returnValue = $row["value"];
                    }
                    
                    // use probabilistic early expiration to check if the cache should be refreshed
                    $item = new Item(
                        $key,
                        $returnValue,
                        !empty($returnValue) ? Item::cacheHit : Item::cacheNone,
                        $expireDate,
                        $expireDate,
                        $row["delta"]
                    );
                    
                    if ((($default instanceof \Closure) && is_callable($default)) && (($beta == INF) || (($expireDate !== null) && ($row["processing"] == 0)))) {
                        $isHit = $item->isHit();
                        $betaInf = $beta == INF;
                        $randomFloat = mt_rand() / mt_getrandmax();
                        $preGenerate = (($row["delta"] > 0) && (time() - $row["delta"] * $beta * $randomFloat > $expireDate->getTimestamp()));
                        
                        if (!$isHit || $betaInf || $preGenerate) {
                            try {
                                $this->db->Execute("
                                    UPDATE `st_cache` SET
                                        `processing`=1
                                    WHERE `id`=:id
                                ", [
                                    'id' => $row["id"]
                                ]);
                                
                                $start = time();
                                $response = $default($item);
                                
                                if ($response !== null) {
                                    if (!is_a($response, Item::class)) {
                                        $item->set($response);
                                    }
    
                                    $item->setDelta(time() - $start);
                                    $this->save($item);
                                }
                            } catch (\Exception $e) {
                                $this->db->Execute("
                                    UPDATE `st_cache` SET
                                        `processing`=0
                                    WHERE `id`=:id
                                ", [
                                    'id' => $row["id"]
                                ]);
                                
                                throw $e;
                            }
                        }
                    }
                    
                    return $item;
                } elseif (($default instanceof \Closure) && is_callable($default)) {
                    $start = time();
                    $item = new Item($key);
                    $response = $default($item);
    
                    if ($response !== null) {
                        if (!is_a($response, Item::class)) {
                            $item->set($response);
                        }
    
                        $item->setDelta(time() - $start);
                        $this->save($item);
                    }
                    
                    return $item;
                } else {
                    return new Item($key, null);
                }
            }
            
            return new Item($key, null, Item::cacheNone, null);
        }
        
        /**
         * Removes a key from the cache
         * @param string $key
         * @return void
         */
        public function deleteItem(string $key): void {
            if (!empty($key)) {
                $key = str_replace("%", "\%", $key);
                $key = str_replace("_", "\_", $key);
                $key = str_replace("*", "%", $key);
                $key = str_replace("?", "_", $key);
                
                $this->db->Execute("
                    DELETE
                    FROM `st_cache`
                    WHERE `key` LIKE :key
                ", [
                    'key' => $key
                ]);
            }
        }
    }