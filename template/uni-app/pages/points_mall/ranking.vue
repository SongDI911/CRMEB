<template>
  <view class="ranking" :style="colorStyle">
    <view class="hero">
      <view class="eyebrow">每一份付出，都值得记录</view>
      <view class="title">积分排行榜</view>
      <view class="subtitle">按累计获赠积分排名，兑换礼品不影响排名</view>
      <view class="my-stats">
        <view><text class="value">{{ my.rank || '—' }}</text><text>我的排名</text></view>
        <view><text class="value">{{ my.total_integral }}</text><text>累计获赠积分</text></view>
        <view><text class="value">{{ my.available_integral }}</text><text>可用积分</text></view>
      </view>
    </view>
    <view class="board">
      <view class="board-title">累计积分总榜 <text>同分按用户编号排序</text></view>
      <view v-for="item in list" :key="item.rank" class="row" :class="{ mine: item.is_me }">
        <text class="rank" :class="{ medal: item.rank <= 3 }">{{ item.rank }}</text>
        <image v-if="item.avatar" class="avatar" :src="item.avatar" mode="aspectFill" />
        <view v-else class="avatar placeholder">{{ (item.nickname || '用户').slice(0, 1) }}</view>
        <view class="name">{{ item.nickname || '用户' }}<text v-if="item.is_me" class="me">我</text></view>
        <view class="points">{{ item.total_integral }}<text>积分</text></view>
      </view>
      <view v-if="error" class="state" @click="load">{{ error }} · 点击重试</view>
      <view v-else class="state">{{ loading ? '加载中…' : (!list.length ? '暂无排名' : (finished ? '已显示全部用户' : '上拉加载更多')) }}</view>
    </view>
    <navigator class="exchange" url="/pages/points_mall/index">去积分商城兑换礼品</navigator>
  </view>
</template>

<script>
import { mapGetters } from 'vuex';
import { getIntegralRanking } from '@/api/points_mall.js';
import { toLogin } from '@/libs/login.js';
import colors from '@/mixins/color';
export default {
  mixins: [colors],
  data() { return { list: [], my: { rank: null, total_integral: 0, available_integral: 0 }, page: 1, loading: false, finished: false, error: '' }; },
  computed: mapGetters(['isLogin']),
  onShow() { this.refresh(); },
  onPullDownRefresh() { this.refresh(); },
  onReachBottom() { this.load(); },
  methods: {
    refresh() {
      if (!this.isLogin) { uni.stopPullDownRefresh(); return toLogin(); }
      if (this.loading) { uni.stopPullDownRefresh(); return; }
      this.page = 1;
      this.finished = false;
      this.list = [];
      this.load();
    },
    async load() {
      if (!this.isLogin || this.loading || this.finished) return;
      this.loading = true;
      this.error = '';
      try {
        const { data } = await getIntegralRanking({ page: this.page, limit: 20 });
        this.my = data.my;
        this.list = this.list.concat(data.list);
        this.finished = this.list.length >= data.count || data.list.length < 20;
        this.page += 1;
      } catch (error) { this.error = typeof error === 'string' ? error : '加载失败'; }
      finally { this.loading = false; uni.stopPullDownRefresh(); }
    }
  }
};
</script>

<style lang="scss" scoped>
.ranking { min-height: 100vh; background: #f5f6f8; padding-bottom: 60rpx; color: #222; }
.hero { padding: 48rpx 30rpx; background: var(--view-theme); color: #fff; }
.eyebrow { font-size: 24rpx; opacity: .8; }
.title { font-size: 48rpx; font-weight: bold; margin: 12rpx 0; }
.subtitle { font-size: 24rpx; opacity: .9; }
.my-stats { display: flex; margin-top: 40rpx; padding: 26rpx 0; background: rgba(255,255,255,.15); border-radius: 20rpx; }
.my-stats > view { flex: 1; text-align: center; font-size: 22rpx; }
.my-stats text { display: block; }
.my-stats .value { font-size: 36rpx; font-weight: bold; margin-bottom: 10rpx; }
.board { margin: 24rpx; border-radius: 20rpx; background: #fff; padding: 0 24rpx; }
.board-title { padding: 30rpx 0; font-size: 30rpx; font-weight: bold; }
.board-title text { display: block; color: #888; font-size: 22rpx; font-weight: normal; margin-top: 8rpx; }
.row { display: flex; align-items: center; padding: 26rpx 0; border-top: 1rpx solid #f2f2f2; }
.mine { background: #f8f9fc; }
.rank { width: 60rpx; color: #999; font-size: 28rpx; text-align: center; }
.medal { color: #bd7c26; font-weight: bold; font-size: 36rpx; }
.avatar { width: 72rpx; height: 72rpx; border-radius: 50%; margin: 0 18rpx 0 8rpx; flex-shrink: 0; }
.placeholder { background: #edf0f5; text-align: center; line-height: 72rpx; color: #64748b; }
.name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 28rpx; }
.me { font-size: 20rpx; color: var(--view-theme); margin-left: 10rpx; }
.points { font-size: 30rpx; font-weight: bold; margin-left: 12rpx; }
.points text { display: block; text-align: right; font-size: 20rpx; color: #999; font-weight: normal; }
.state { padding: 32rpx 0; text-align: center; font-size: 24rpx; color: #888; }
.exchange { margin: 30rpx 24rpx; padding: 26rpx; background: var(--view-theme); color: #fff; text-align: center; border-radius: 14rpx; }
</style>
