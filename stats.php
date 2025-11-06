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

    public function status(int $now): string
    {
        // 获取 active/idle
        $active = $this->isActive($now);

        // 读取 burst session
        $session = Minz_Session::param('autottl_burst', []);
        $burst = isset($session[$this->id]) && $session[$this->id]['burst'] === true;

        // 优先判断 burst
        if ($burst) {
            return 'burst';
        }
        return $active ? 'active' : 'idle';
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
     * @var int
     */
    private $minTTL;

    /**
     * @var string
     */
    private string $avgSource;

    // burst 参数
    private int $burstThreshold = 15; // 触发爆发阈值
    private int $burstMaxMiss = 3;    // 连续 miss 上限

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount, int $minTTL, string $avgSource = 'lastSeen')
    {
        parent::__construct();
        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
        $this->minTTL = $minTTL;
        $this->avgSource = $avgSource;
    }

    private function computeAvgTTL(array $timestamps): int
    {
        if (count($timestamps) < 2) {
            return $this->defaultTTL;
        }

        rsort($timestamps);

        $diffs = [];
        for ($i = 0; $i < count($timestamps) - 1; $i++) {
            $diffs[] = $timestamps[$i] - $timestamps[$i + 1];
        }

        sort($diffs);

        $trim = floor(count($diffs) * 0.1);
        if ($trim > 0) {
            $diffs = array_slice($diffs, $trim, -$trim);
        }

        $mid = floor(count($diffs) / 2);
        $median = (count($diffs) % 2 === 0)
            ? ($diffs[$mid - 1] + $diffs[$mid]) / 2
            : $diffs[$mid];

        if ($median < $this->minTTL) return $this->minTTL;
        if ($median > $this->maxTTL) return $this->maxTTL;

        return (int)$median;
    }

    public function calcAdjustedTTL(int $avgTTL, int $dateMax): int
    {
        $timeSinceLastEntry = time() - $dateMax;

        if ($this->defaultTTL > $this->maxTTL) {
            return $this->defaultTTL;
        }

        if ($avgTTL > $this->maxTTL || $timeSinceLastEntry > 2 * $this->maxTTL) {
            return $this->maxTTL;
        } elseif ($avgTTL === 0 ) {
            return $this->defaultTTL;
        } elseif ($avgTTL < $this->minTTL) {
            return $this->minTTL;
        }

        return $avgTTL;
    }

    // ============ Burst 模式核心逻辑（固定使用 lastSeen） ============
    private function handleBurst(int $feedID): bool
    {
        // 固定字段 lastSeen —— 与 avgSource 解耦
        $sql = <<<SQL
SELECT stats.lastSeen AS t
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
ORDER BY stats.lastSeen DESC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $rows = $stm->fetchAll(PDO::FETCH_COLUMN);
        $rows = array_map('intval', $rows);

        // 新增条目数量 = 最新窗口大小
        $newItems = count($rows);

        $session = Minz_Session::param('autottl_burst', []);

        if (!isset($session[$feedID])) {
            $session[$feedID] = [
                'burst' => false,
                'miss'  => 0
            ];
        }

        // 如果有大爆发，重置 miss，进入 burst
        if ($newItems > $this->burstThreshold) {
            $session[$feedID]['burst'] = true;
            $session[$feedID]['miss']  = 0;
        } else {
            // 如果在 burst 状态却本次没内容 → miss++
            if ($session[$feedID]['burst']) {
                $session[$feedID]['miss']++;
                // 连续 miss 达标 → 退出 burst
                if ($session[$feedID]['miss'] >= $this->burstMaxMiss) {
                    $session[$feedID]['burst'] = false;
                    $session[$feedID]['miss']  = 0;
                }
            }
        }

        Minz_Session::_param('autottl_burst', $session);
        return $session[$feedID]['burst'];
    }

    public function getAdjustedTTL(int $feedID): int
    {
        $field = ($this->avgSource === 'date') ? 'date' : 'lastSeen';
        $sql = <<<SQL
SELECT stats.$field AS t
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
ORDER BY stats.$field DESC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $rows = $stm->fetchAll(PDO::FETCH_COLUMN);
        $rows = array_map('intval', $rows);

        $avg = $this->computeAvgTTL($rows);

        // 计算本次新增条目数（当前 max timestamp - 上次 max timestamp）
        $newItems = count($rows);

        // Burst 检测
        if ($this->handleBurst($feedID, $newItems)) {
            return $this->minTTL;
        }

        return $this->calcAdjustedTTL($avg, (int)max($rows));
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

        $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.`lastUpdate`,
    feed.ttl,
    CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.$field) - MIN(stats.$field)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
    MAX(stats.$field) AS date_max
FROM `_feed` AS feed
LEFT JOIN `_entry` AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
ORDER BY `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

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
