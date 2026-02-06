<?php

class StatItem
{
    public int $id;

    public string $name;

    public int $lastUpdate;

    public int $ttl;

    public int $avgTTL;

    public int $dateMax;

    private int $maxTTL;

    public function __construct(array $feed, int $maxTTL)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastUpdate = (int) $feed['lastUpdate'];
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
        $this->dateMax = (int) $feed['date_max'];
        $this->maxTTL = $maxTTL;
    }

    public function isActive(int $now): bool
    {
        $timeSinceLastEntry = $now - $this->dateMax;

        if ($timeSinceLastEntry > 2 * $this->maxTTL) {
            return false;
        }

        return true;
    }
}

class AutoTTLStats extends Minz_ModelPdo
{
    /**
     * @var int
     */
    private $defaultTTL;

    /**
     * @var int
     */
    private $maxTTL;

    /**
     * @var int
     */
    private $statsCount;

    /**
     * @var string
     */
    private $avgSource;

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount, string $avgSource)
    {
        parent::__construct();

        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
        $this->avgSource = $avgSource;
    }

    public function calcAdjustedTTL(int $avgTTL, int $dateMax): int
    {
        $timeSinceLastEntry = time() - $dateMax;

        if ($this->defaultTTL > $this->maxTTL) {
            return $this->defaultTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $this->maxTTL || $timeSinceLastEntry > 2 * $this->maxTTL) {
            return $this->maxTTL;
        } elseif ($avgTTL < $this->defaultTTL) {
            return $this->defaultTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(int $feedID): int
    {
        if ($this->avgSource === 'date') {
            $sqlDate = <<<SQL
SELECT
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.date) AS `date_max`
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
SQL;
            $stm = $this->pdo->query($sqlDate);
            $res = $stm->fetch(PDO::FETCH_NAMED);
            return $this->calcAdjustedTTL((int) $res['avgTTL'], (int) $res['date_max']);
        }

        // First compute using lastSeen
        $sqlLastSeen = <<<SQL
SELECT
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.lastSeen) - MIN(stats.lastSeen)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.lastSeen) AS `date_max`
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
SQL;
        $stm = $this->pdo->query($sqlLastSeen);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        $avgTTL = (int) $res['avgTTL'];
        $dateMax = (int) $res['date_max'];

        if ($avgTTL === 0) {
            // Fallback: only compute date when necessary
            $sqlDate = <<<SQL
SELECT
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.date) AS `date_max`
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
SQL;
            $stm = $this->pdo->query($sqlDate);
            $res = $stm->fetch(PDO::FETCH_NAMED);
            $avgTTL = (int) $res['avgTTL'];
            $dateMax = (int) $res['date_max'];
        }

        return $this->calcAdjustedTTL($avgTTL, $dateMax);
    }

    public function getFeedStats(bool $autoTTL): array
    {
        $field = ($this->avgSource === 'date') ? 'date' : 'lastSeen';
        $where = '';
        if ($autoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        if ($field === 'date') {
            $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.`lastUpdate`,
    feed.ttl,
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.date) AS date_max
FROM `_feed` AS feed
LEFT JOIN `_entry` AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
ORDER BY `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;
        } else {
            // Phase 1: compute using lastSeen for all feeds (no LIMIT to allow accurate reordering after fallback)
            $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.`lastUpdate`,
    feed.ttl,
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.lastSeen) - MIN(stats.lastSeen)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.lastSeen) AS date_max
FROM `_feed` AS feed
LEFT JOIN `_entry` AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
SQL;
        }

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        // For lastSeen: only compute date for feeds that need fallback
        if ($field === 'lastSeen' && !empty($res)) {
            $fallbackIds = [];
            foreach ($res as $row) {
                if ((int) $row['avgTTL'] === 0) {
                    $fallbackIds[] = (int) $row['id'];
                }
            }

            if (!empty($fallbackIds)) {
                $idsSql = implode(',', array_map('intval', $fallbackIds));
                $sqlDate = <<<SQL
SELECT
    feed.id,
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL_date`,
    MAX(stats.date) AS `date_max_date`
FROM `_feed` AS feed
LEFT JOIN `_entry` AS stats ON feed.id = stats.id_feed
WHERE feed.id IN ({$idsSql})
GROUP BY feed.id
SQL;
                $stmDate = $this->pdo->query($sqlDate);
                $dateRows = $stmDate->fetchAll(PDO::FETCH_NAMED);
                $dateMap = [];
                foreach ($dateRows as $dr) {
                    $dateMap[(int) $dr['id']] = $dr;
                }

                foreach ($res as &$row) {
                    $id = (int) $row['id'];
                    if ((int) $row['avgTTL'] === 0 && isset($dateMap[$id])) {
                        $row['avgTTL'] = (int) $dateMap[$id]['avgTTL_date'];
                        $row['date_max'] = (int) $dateMap[$id]['date_max_date'];
                    }
                }
                unset($row);
            }

            // Reorder after fallback and apply limit
            usort($res, function ($a, $b) {
                return (int) $a['avgTTL'] <=> (int) $b['avgTTL'];
            });
            $res = array_slice($res, 0, $this->statsCount);
        }

        $list = [];
        foreach ($res as $feed) {
            $list[] = new StatItem($feed, $this->maxTTL);
        }

        return $list;
    }

    public function humanIntervalFromSeconds(int $seconds): string
    {
        $from = new \DateTime('@0');
        $to = new \DateTime("@$seconds");
        $interval = $from->diff($to);

        $results = [];

        if ($interval->y === 1) {
            $results[] = "{$interval->y} year";
        } elseif ($interval->y > 1) {
            $results[] = "{$interval->y} years";
        }

        if ($interval->m === 1) {
            $results[] = "{$interval->m} month";
        } elseif ($interval->m > 1) {
            $results[] = "{$interval->m} months";
        }

        if ($interval->d === 1) {
            $results[] = "{$interval->d} day";
        } elseif ($interval->d > 1) {
            $results[] = "{$interval->d} days";
        }

        if ($interval->h === 1) {
            $results[] = "{$interval->h} hour";
        } elseif ($interval->h > 1) {
            $results[] = "{$interval->h} hours";
        }

        if ($interval->i === 1) {
            $results[] = "{$interval->i} minute";
        } elseif ($interval->i > 1) {
            $results[] = "{$interval->i} minutes";
        } elseif ($interval->i === 0 && $interval->s === 1) {
            $results[] = "{$interval->s} second";
        } elseif ($interval->i === 0 && $interval->s > 1) {
            $results[] = "{$interval->s} seconds";
        }

        return implode(' ', $results);
    }
}
        
