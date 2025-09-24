import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// ��� Laravel Echo Configuration
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: 'local-agent-console-key',
    wsHost: window.location.hostname,
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
    enableLogging: true,
    disableStats: true,
});

console.log('��� Laravel Echo initialized');

export default window.Echo;
