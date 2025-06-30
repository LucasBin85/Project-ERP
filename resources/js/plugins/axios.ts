import axios from 'axios'
import { useLoadingStore } from '@/stores/loading'

// Intercepta requisição
axios.interceptors.request.use(config => {
  const loading = useLoadingStore()
  loading.start()
  return config
}, error => {
  const loading = useLoadingStore()
  loading.stop()
  return Promise.reject(error)
})

// Intercepta resposta
axios.interceptors.response.use(response => {
  const loading = useLoadingStore()
  loading.stop()
  return response
}, error => {
  const loading = useLoadingStore()
  loading.stop()
  return Promise.reject(error)
})

export default axios
