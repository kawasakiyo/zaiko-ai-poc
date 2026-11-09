<template>
  <div>
    <section class="card">
      <h3>分析履歴</h3>
      <p class="muted">過去のプロンプト・AIの説明・生成SQLを新しい順に表示します。行をクリックで詳細、SQLはその場で再実行できます。</p>
    </section>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading" class="muted">読み込み中...</p>

    <section v-if="!loading && !items.length" class="card">
      <p class="muted">履歴はまだありません。</p>
    </section>

    <section v-for="item in items" :key="item.Id" class="card item">
      <div class="item-head" @click="toggle(item.Id)">
        <span class="prompt-text">{{ item.Prompt }}</span>
        <span class="meta">
          <span class="user-badge">{{ item.Username || '(不明)' }}</span>
          {{ item.Model }} / {{ formatDate(item.CreatedAt) }}
        </span>
      </div>

      <div v-if="expanded === item.Id" class="item-body">
        <template v-if="item.Explanation">
          <h4>説明</h4>
          <p class="explanation">{{ item.Explanation }}</p>
        </template>

        <h4>生成されたSQL</h4>
        <pre class="sql">{{ item.GeneratedSql }}</pre>

        <div class="rerun-bar">
          <button class="btn" :disabled="rerunning[item.Id]" @click="rerun(item)">
            {{ rerunning[item.Id] ? '再実行中...' : 'このSQLを再実行' }}
          </button>
        </div>

        <p v-if="rerunError[item.Id]" class="error">{{ rerunError[item.Id] }}</p>

        <div v-if="results[item.Id]" class="result">
          <div class="result-head">
            <h4>再実行の結果（{{ results[item.Id].rows.length }} 行）</h4>
            <button class="btn-secondary" @click="downloadCsv(item.Id)">CSVダウンロード</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th v-for="c in results[item.Id].columns" :key="c">{{ c }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in results[item.Id].rows" :key="i">
                  <td v-for="c in results[item.Id].columns" :key="c">{{ formatCell(r[c]) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!results[item.Id].rows.length" class="muted">該当する行がありませんでした。</p>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  name: 'HistoryView',
  data() {
    return {
      items: [],
      loading: true,
      error: '',
      expanded: null,
      // 履歴ID をキーにした再実行の状態。
      rerunning: {},
      rerunError: {},
      results: {},
    }
  },
  async created() {
    try {
      const { data } = await axios.get('/api/history')
      this.items = data.items || []
    } catch (e) {
      this.error = '履歴の取得に失敗しました。'
    } finally {
      this.loading = false
    }
  },
  methods: {
    toggle(id) {
      this.expanded = this.expanded === id ? null : id
    },
    formatDate(v) {
      if (!v) return ''
      const d = new Date(v)
      return isNaN(d.getTime()) ? v : d.toLocaleString('ja-JP')
    },
    formatCell(v) {
      if (v === null || v === undefined) return ''
      return String(v)
    },
    async rerun(item) {
      // Vue 2 ではオブジェクトのキー追加をリアクティブにするため $set を使う。
      this.$set(this.rerunning, item.Id, true)
      this.$set(this.rerunError, item.Id, '')
      try {
        const { data } = await axios.post('/api/rerun', { id: item.Id })
        this.$set(this.results, item.Id, { columns: data.columns || [], rows: data.rows || [] })
      } catch (e) {
        const res = e.response
        this.$set(
          this.rerunError,
          item.Id,
          (res && res.data && res.data.error) || '再実行に失敗しました。'
        )
      } finally {
        this.$set(this.rerunning, item.Id, false)
      }
    },
    async downloadCsv(id) {
      const result = this.results[id]
      if (!result) return
      try {
        const res = await axios.post(
          '/api/export',
          { columns: result.columns, rows: result.rows },
          { responseType: 'blob' }
        )
        const url = window.URL.createObjectURL(new Blob([res.data]))
        const a = document.createElement('a')
        a.href = url
        a.download = 'result.csv'
        document.body.appendChild(a)
        a.click()
        a.remove()
        window.URL.revokeObjectURL(url)
      } catch (e) {
        this.$set(this.rerunError, id, 'CSVダウンロードに失敗しました。')
      }
    },
  },
}
</script>

<style scoped>
.card {
  background: #fff;
  border: 1px solid #e4e7eb;
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 12px;
}
.item-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  cursor: pointer;
}
.prompt-text {
  font-size: 14px;
}
.meta {
  font-size: 12px;
  color: #9aa5b1;
  white-space: nowrap;
}
.user-badge {
  background: #e4e7eb;
  color: #52606d;
  border-radius: 4px;
  padding: 1px 6px;
  margin-right: 6px;
}
.item-body {
  margin-top: 12px;
  border-top: 1px solid #eef1f4;
  padding-top: 12px;
}
.item-body h4 {
  margin: 10px 0 6px;
  font-size: 13px;
  color: #52606d;
}
.explanation {
  white-space: pre-wrap;
  line-height: 1.6;
  margin: 0;
}
.sql {
  background: #0f172a;
  color: #e2e8f0;
  padding: 12px;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 13px;
  margin: 0;
}
.rerun-bar {
  margin-top: 12px;
}
.btn {
  background: #3b82f6;
  color: #fff;
  border: none;
  padding: 8px 18px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
}
.btn:disabled {
  background: #a5b4fc;
  cursor: not-allowed;
}
.btn-secondary {
  background: #fff;
  color: #3b82f6;
  border: 1px solid #3b82f6;
  padding: 6px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
}
.result {
  margin-top: 14px;
}
.result-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.table-wrap {
  overflow-x: auto;
}
table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}
th,
td {
  border: 1px solid #e4e7eb;
  padding: 6px 10px;
  text-align: left;
  white-space: nowrap;
}
th {
  background: #f1f5f9;
}
.muted {
  color: #9aa5b1;
}
.error {
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 10px 12px;
  border-radius: 6px;
}
</style>
