<template>
  <view class="points-page" :style="colorStyle">
    <view class="summary">
      <view>可用积分</view><view class="balance">{{ my.available_integral }}</view>
      <view class="totals">累计获赠 {{ my.total_integral }} 分 · 我的排名 {{ my.rank || '—' }}</view>
    </view>
    <view class="links">
      <navigator url="/pages/points_mall/index">积分商城</navigator>
      <navigator url="/pages/points_mall/ranking">积分排行榜</navigator>
      <navigator url="/pages/points_mall/exchange_record">兑换记录</navigator>
    </view>
    <view class="rules">
      <view class="heading">积分规则</view>
      <view>积分仅由管理员发放。兑换礼品扣除可用积分，不影响累计积分和排名。管理员撤销错发积分会同步修正累计积分。</view>
    </view>
    <view class="records">
      <view class="heading">积分明细</view>
      <view v-for="(item, index) in list" :key="index" class="record">
        <view class="detail"><view>{{ item.title }}</view><view class="muted">{{ item.mark }}</view><view class="muted">{{ item.add_time }}</view></view>
        <text class="amount" :class="{ income: Number(item.pm) === 1 }">{{ Number(item.pm) === 1 ? '+' : '−' }}{{ item.number }}</text>
      </view>
      <view class="state" v-if="error" @click="refresh">{{ error }} · 点击重试</view>
      <view class="state" v-else>{{ loading ? '加载中…' : (!list.length ? '暂无积分记录' : (finished ? '已显示全部记录' : '上拉加载更多')) }}</view>
    </view>
  </view>
</template>

<script>
import { mapGetters } from 'vuex';
import { getIntegralList } from '@/api/user.js';
import { getIntegralRanking } from '@/api/points_mall.js';
import { toLogin } from '@/libs/login.js';
import colors from '@/mixins/color';
export default {
  mixins: [colors],
  data() { return { my: { available_integral: 0, total_integral: 0, rank: null }, list: [], page: 1, loading: false, finished: false, error: '' }; },
  computed: mapGetters(['isLogin']),
  onShow() { this.refresh(); },
  onReachBottom() { this.load(); },
  methods: {
    async refresh() {
      if (!this.isLogin) return toLogin();
      if (this.loading) return;
      this.page = 1;
      this.list = [];
      this.finished = false;
      this.error = '';
      await this.load();
      try { const { data } = await getIntegralRanking({ limit: 1 }); this.my = data.my; }
      catch (error) { this.error = typeof error === 'string' ? error : '积分加载失败'; }
    },
    async load() {
      if (!this.isLogin || this.loading || this.finished) return;
      this.loading = true;
      this.error = '';
      try {
        const { data } = await getIntegralList({ page: this.page, limit: 20 });
        this.list = this.list.concat(data);
        this.finished = data.length < 20;
        this.page += 1;
      } catch (error) { this.error = typeof error === 'string' ? error : '明细加载失败'; }
      finally { this.loading = false; }
    }
  }
};
</script>

<style lang="scss" scoped>
.points-page { min-height: 100vh; background: #f5f6f8; padding-bottom: 40rpx; color: #222; }
.summary { padding: 48rpx 30rpx; text-align: center; background: var(--view-theme); color: #fff; font-size: 28rpx; }
.balance { font-size: 76rpx; font-weight: bold; margin: 18rpx 0; }
.totals { font-size: 24rpx; opacity: .9; }
.links { display: flex; margin: 24rpx; padding: 30rpx 12rpx; background: #fff; border-radius: 16rpx; }
.links navigator { flex: 1; text-align: center; font-size: 26rpx; color: var(--view-theme); }
.rules, .records { margin: 24rpx; background: #fff; padding: 28rpx; border-radius: 16rpx; }
.rules { font-size: 24rpx; line-height: 1.8; color: #666; }
.heading { color: #222; font-size: 30rpx; font-weight: bold; margin-bottom: 20rpx; }
.record { display: flex; align-items: center; justify-content: space-between; padding: 24rpx 0; border-top: 1rpx solid #f2f2f2; font-size: 28rpx; }
.detail { flex: 1; min-width: 0; overflow-wrap: break-word; }
.muted { color: #999; font-size: 22rpx; margin-top: 10rpx; }
.amount { font-weight: bold; margin-left: 20rpx; }
.income { color: var(--view-theme); }
.state { text-align: center; color: #999; font-size: 24rpx; padding: 22rpx 0; }
</style>
