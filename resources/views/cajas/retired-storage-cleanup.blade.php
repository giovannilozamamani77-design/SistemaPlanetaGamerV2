{{-- Remove only abandoned closing recovery copies; never read or transmit their values. --}}
<div x-data x-init="(() => { try { for (let i = localStorage.length - 1; i >= 0; i--) { const key = localStorage.key(i); if (key?.startsWith('pg-close-draft:')) localStorage.removeItem(key); } } catch (error) {} })()"></div>
