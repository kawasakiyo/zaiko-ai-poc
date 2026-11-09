import Vue from 'vue'
import VueRouter from 'vue-router'
import axios from 'axios'
import App from './App.vue'
import router from './router'

Vue.use(VueRouter)
Vue.config.productionTip = false

// 起動時に保存済みトークンを axios の既定 Authorization ヘッダへ設定。
const token = localStorage.getItem('token')
if (token) {
  axios.defaults.headers.common.Authorization = 'Bearer ' + token
}

// 401 を受けたらトークンを破棄してログイン画面へ。
axios.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response && err.response.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('username')
      delete axios.defaults.headers.common.Authorization
      if (router.currentRoute.path !== '/login') {
        router.push('/login')
      }
    }
    return Promise.reject(err)
  }
)

new Vue({
  router,
  render: (h) => h(App),
}).$mount('#app')
