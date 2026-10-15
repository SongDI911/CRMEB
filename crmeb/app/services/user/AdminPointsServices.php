<?php
declare(strict_types=1);

namespace app\services\user;

use crmeb\exceptions\AdminException;
use think\facade\Db;

/** Administrator awards are the only source of new points. Refunds are not awards. */
class AdminPointsServices
{
    const AWARD = 'admin_award';
    const REVOKE = 'admin_revoke';

    private function bills(int $uid = 0)
    {
        $query = Db::name('user_bill')->where('category', 'integral')->where('status', 1)
            ->whereIn('type', [self::AWARD, self::REVOKE]);
        return $uid ? $query->where('uid', $uid) : $query;
    }

    public function total(int $uid): int
    {
        $row = $this->bills($uid)->fieldRaw('COALESCE(SUM(CASE WHEN pm = 1 THEN number ELSE -number END), 0) AS total')->find();
        return max(0, (int)$row['total']);
    }

    public function today(int $uid): int
    {
        return (int)$this->bills($uid)->where('type', self::AWARD)->whereDay('add_time')->sum('number');
    }

    public function adjust(int $uid, $number, int $direction, int $adminId, string $reason): bool
    {
        if ($adminId <= 0) throw new AdminException('仅管理员可以发放或撤销积分');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', (string)$number) || !in_array($direction, [1, 2], true)) {
            throw new AdminException('积分必须为1至999999999之间的整数');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) throw new AdminException('请填写200字以内的积分操作原因');
        $number = (int)$number;
        return Db::transaction(function () use ($uid, $number, $direction, $adminId, $reason) {
            $user = Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$user || !$user['status'] || $user['is_del']) throw new AdminException('用户不存在或已停用');
            $frozen = (int)Db::name('user_bill')->where('uid', $uid)->where('category', 'integral')
                ->where('frozen_time', '>', time())->sum('number');
            $balance = (int)$user['integral'];
            if ($direction === 2 && ($number > $balance - $frozen || $number > $this->total($uid))) {
                throw new AdminException('撤销积分不能超过可用积分或累计获赠积分');
            }
            $balance += $direction === 1 ? $number : -$number;
            if ($balance > 999999999) throw new AdminException('积分余额超过上限');
            if (!Db::name('user')->where('uid', $uid)->update(['integral' => $balance])) {
                throw new AdminException('积分更新失败');
            }
            if (!Db::name('user_bill')->insert([
                'uid' => $uid, 'link_id' => $adminId, 'category' => 'integral',
                'type' => $direction === 1 ? self::AWARD : self::REVOKE,
                'title' => $direction === 1 ? '管理员发放积分' : '管理员撤销积分',
                'number' => $number, 'balance' => $balance, 'pm' => $direction === 1 ? 1 : 0,
                'mark' => $reason, 'status' => 1, 'add_time' => time(),
            ])) throw new AdminException('积分流水保存失败');
            return true;
        });
    }

    private function rankedUsers()
    {
        $totals = $this->bills()->fieldRaw('uid, SUM(CASE WHEN pm = 1 THEN number ELSE -number END) AS total')
            ->group('uid')->buildSql();
        return Db::name('user')->alias('u')->leftJoin([$totals => 'p'], 'p.uid = u.uid')
            ->where('u.status', 1)->where('u.is_del', 0);
    }

    public function ranking(int $uid, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        // Stable ties: smaller user id first. Only nickname/avatar are exposed.
        $list = $this->rankedUsers()->field('u.uid,u.nickname,u.avatar')
            ->fieldRaw('COALESCE(p.total, 0) AS total_integral')->orderRaw('COALESCE(p.total, 0) DESC, u.uid ASC')
            ->page($page, $limit)->select()->toArray();
        foreach ($list as $index => &$row) {
            $row['rank'] = ($page - 1) * $limit + $index + 1;
            $row['total_integral'] = (int)$row['total_integral'];
            $row['is_me'] = (int)$row['uid'] === $uid;
            unset($row['uid']);
        }
        unset($row);
        $total = $this->total($uid);
        $me = Db::name('user')->where('uid', $uid)->where('status', 1)->where('is_del', 0)->field('integral')->find();
        $rank = $me ? 1 + $this->rankedUsers()->where(function ($query) use ($total, $uid) {
            $query->whereRaw('COALESCE(p.total, 0) > :points OR (COALESCE(p.total, 0) = :same_points AND u.uid < :uid)',
                ['points' => [$total, \PDO::PARAM_INT], 'same_points' => [$total, \PDO::PARAM_INT], 'uid' => [$uid, \PDO::PARAM_INT]]);
        })->count() : null;
        $frozen = (int)Db::name('user_bill')->where('uid', $uid)->where('category', 'integral')->where('frozen_time', '>', time())->sum('number');
        return [
            'list' => $list, 'count' => (int)Db::name('user')->where('status', 1)->where('is_del', 0)->count(),
            'my' => ['rank' => $rank, 'total_integral' => $total, 'available_integral' => max(0, (int)($me['integral'] ?? 0) - $frozen)],
        ];
    }
}
