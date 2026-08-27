import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// For same-origin session-authenticated calls (Inertia + Sanctum statefulApi),
// let axios pick up the XSRF token cookie automatically.
