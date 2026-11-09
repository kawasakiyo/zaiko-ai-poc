<template>
  <div class="login-wrap">
    <form class="login-card" @submit.prevent="submit">
      <h2>ログイン</h2>
      <p class="muted">在庫データ分析AI (POC)</p>

      <label class="label">ユーザー名</label>
      <input v-model="username" class="input" type="text" autocomplete="username" />

      <label class="label">パスワード</label>
      <input v-model="password" class="input" type="password" autocomplete="current-password" />

      <p v-if="error" class="error">{{ error }}</p>

      <button class="btn" type="submit" :disabled="loading || !username || !password">
        {{ loading ? 'ログイン中...' : 'ログイン' }}
      </button>
    </form>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  name: 'LoginView',
  data() {
    return {
      username: '',
      password: '',
      loading: false,
      error: '',
    }
  },
  methods: {
    async submit() {
      this.loading = true
      this.error = ''
      try {
        const { data } = await axios.post('/api/login', {
          username: this.username,
          password: this.password,
        })
        localStorage.setItem('token', data.token)
        localStorage.setItem('username', data.username)
        axios.defaults.headers.common.Authorization = 'Bearer ' + data.token
        this.$router.push('/')
      } catch (e) {
        const res = e.response
        this.error = (res && res.data && res.data.error) || 'ログインに失敗しました。'
      } finally {
        this.loading = false
      }
    },
  },
}
</script>

<style scoped>
.login-wrap {
  display: flex;
  justify-content: center;
  margin-top: 60px;
}
.login-card {
  background: #fff;
  border: 1px solid #e4e7eb;
  border-radius: 8px;
  padding: 28px;
  width: 340px;
}
.login-card h2 {
  margin: 0 0 4px;
}
.muted {
  color: #9aa5b1;
  margin-top: 0;
  font-size: 13px;
}
.label {
  display: block;
  font-size: 13px;
  color: #52606d;
  margin: 12px 0 4px;
}
.input {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 14px;
}
.btn {
  width: 100%;
  margin-top: 18px;
  background: #3b82f6;
  color: #fff;
  border: none;
  padding: 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
}
.btn:disabled {
  background: #a5b4fc;
  cursor: not-allowed;
}
.error {
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 8px 10px;
  border-radius: 6px;
  font-size: 13px;
  margin-top: 12px;
}
</style>
