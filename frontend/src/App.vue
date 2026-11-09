<template>
  <div class="app">
    <header class="header">
      <h1>在庫データ分析AI <span class="poc">POC</span></h1>
      <nav v-if="isAuthed">
        <router-link to="/">分析</router-link>
        <router-link to="/history">履歴</router-link>
        <span class="user">{{ username }}</span>
        <a href="#" class="logout" @click.prevent="logout">ログアウト</a>
      </nav>
    </header>
    <main class="main">
      <router-view />
    </main>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  name: 'App',
  computed: {
    // $route を参照することで画面遷移ごとに再評価させる（localStorageは非リアクティブなため）。
    isAuthed() {
      this.$route
      return !!localStorage.getItem('token')
    },
    username() {
      this.$route
      return localStorage.getItem('username') || ''
    },
  },
  methods: {
    async logout() {
      try {
        await axios.post('/api/logout')
      } catch (e) {
        // ログアウトはベストエフォート。失敗してもクライアント側は破棄する。
      }
      localStorage.removeItem('token')
      localStorage.removeItem('username')
      delete axios.defaults.headers.common.Authorization
      this.$router.push('/login')
    },
  },
}
</script>

<style>
* {
  box-sizing: border-box;
}
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Hiragino Kaku Gothic ProN',
    Meiryo, sans-serif;
  color: #1f2933;
  background: #f5f7fa;
}
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #1f2933;
  color: #fff;
}
.header h1 {
  font-size: 18px;
  margin: 0;
}
.poc {
  font-size: 11px;
  background: #3b82f6;
  padding: 2px 6px;
  border-radius: 4px;
  margin-left: 6px;
  vertical-align: middle;
}
.header nav {
  display: flex;
  align-items: center;
}
.header nav a {
  color: #cbd5e1;
  text-decoration: none;
  margin-left: 16px;
  font-size: 14px;
}
.header nav a.router-link-exact-active {
  color: #fff;
  font-weight: bold;
}
.header nav .user {
  margin-left: 20px;
  font-size: 13px;
  color: #9aa5b1;
}
.header nav .logout {
  color: #f0a3a3;
}
.main {
  max-width: 1000px;
  margin: 24px auto;
  padding: 0 16px;
}
</style>
