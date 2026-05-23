import { createPinia } from 'pinia'
import { createApp } from 'vue'
import { router } from '@/router'
import App from '@/App.vue'

import '../css/app.css'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

createApp(App)
    .use(createPinia())
    .use(router)
    .mount('#app')
