import VueRouter from 'vue-router'
import Analysis from './views/Analysis.vue'
import History from './views/History.vue'
import Login from './views/Login.vue'

const routes = [
  { path: '/login', name: 'login', component: Login, meta: { public: true } },
  { path: '/', name: 'analysis', component: Analysis },
  { path: '/history', name: 'history', component: History },
]

const router = new VueRouter({
  mode: 'history',
  routes,
})

// 認証ガード: 未ログインで保護ルートへ来たら /login へ。
router.beforeEach((to, from, next) => {
  const authed = !!localStorage.getItem('token')
  if (!to.meta.public && !authed) {
    next('/login')
  } else if (to.path === '/login' && authed) {
    next('/')
  } else {
    next()
  }
})

export default router
