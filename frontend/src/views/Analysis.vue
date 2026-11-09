<template>
  <div>
    <section class="card">
      <div class="row">
        <label class="label">モデル</label>
        <select v-model="model" class="select">
          <option v-for="m in models" :key="m.id" :value="m.id">{{ m.label }}</option>
        </select>
      </div>
      <textarea
        v-model="prompt"
        class="prompt"
        rows="3"
        placeholder="例: 在庫数量の合計が安全在庫レベルを下回っている製品を一覧にして"
        @keydown.ctrl.enter="analyze"
      ></textarea>
      <div class="actions">
        <span class="hint">Ctrl+Enter で送信</span>
        <button class="btn" :disabled="loading || !prompt.trim()" @click="analyze">
          {{ loading ? '分析中...' : '分析する' }}
        </button>
      </div>
    </section>

    <p v-if="error" class="error">{{ error }}</p>

    <section v-if="explanation" class="card">
      <h3>説明</h3>
      <p class="explanation">{{ explanation }}</p>
      <h3>生成されたSQL</h3>
      <pre class="sql">{{ sql }}</pre>
    </section>

    <section v-if="columns.length" class="card">
      <div class="result-head">
        <h3>結果（{{ rows.length }} 行）</h3>
        <button class="btn-secondary" @click="downloadCsv">CSVダウンロード</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th v-for="c in columns" :key="c">{{ c }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i">
              <td v-for="c in columns" :key="c">{{ formatCell(r[c]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="!rows.length" class="muted">該当する行がありませんでした。</p>
    </section>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  name: 'AnalysisView',
  data() {
    return {
      models: [],
      model: '',
      prompt: '',
      loading: false,
      error: '',
      sql: '',
      explanation: '',
      columns: [],
      rows: [],
    }
  },
  async created() {
    try {
      const { data } = await axios.get('/api/models')
      this.models = data.models || []
      this.model = data.default || (this.models[0] && this.models[0].id) || ''
    } catch (e) {
      this.error = 'モデル一覧の取得に失敗しました。'
    }
  },
  methods: {
    formatCell(v) {
      if (v === null || v === undefined) return ''
      return String(v)
    },
    async analyze() {
      if (!this.prompt.trim()) return
      this.loading = true
      this.error = ''
      try {
        const { data } = await axios.post('/api/analyze', {
          prompt: this.prompt,
          model: this.model,
        })
        this.sql = data.sql
        this.explanation = data.explanation
        this.columns = data.columns || []
        this.rows = data.rows || []
      } catch (e) {
        const res = e.response
        if (res && res.status === 429) {
          const sec = (res.data && res.data.retryAfter) || 0
          this.error = `レート制限を超えました。約 ${sec} 秒後に再試行してください。`
        } else {
          this.error = (res && res.data && res.data.error) || '分析に失敗しました。'
        }
      } finally {
        this.loading = false
      }
    },
    async downloadCsv() {
      try {
        const res = await axios.post(
          '/api/export',
          { columns: this.columns, rows: this.rows },
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
        this.error = 'CSVダウンロードに失敗しました。'
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
  margin-bottom: 16px;
}
.row {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
}
.label {
  font-size: 13px;
  color: #52606d;
  margin-right: 8px;
}
.select {
  padding: 6px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}
.prompt {
  width: 100%;
  padding: 10px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 14px;
  resize: vertical;
}
.actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 8px;
}
.hint {
  font-size: 12px;
  color: #9aa5b1;
}
.btn {
  background: #3b82f6;
  color: #fff;
  border: none;
  padding: 8px 20px;
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
.error {
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 10px 12px;
  border-radius: 6px;
}
.explanation {
  white-space: pre-wrap;
  line-height: 1.6;
}
.sql {
  background: #0f172a;
  color: #e2e8f0;
  padding: 12px;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 13px;
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
</style>
