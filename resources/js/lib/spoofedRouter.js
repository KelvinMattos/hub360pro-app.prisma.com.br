import { router } from '@inertiajs/vue3';

/**
 * Alguns hosts/proxies na frente da produção (Cloudflare, no caso deste
 * projeto — ver CLAUDE.md §6.3) filtram verbos HTTP não-padrão (PATCH/PUT/
 * DELETE) mesmo em chamadas XHR, mesmo quando GET/POST passam sem problema.
 * Spoofar via POST + `_method` na query string é o mecanismo nativo do
 * Symfony/Laravel (Request::getMethod() faz fallback pra query string quando
 * o body não é form-encoded) e funciona em qualquer camada intermediária que
 * só reconheça GET/POST.
 */
function withMethodOverride(url, method) {
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}_method=${method}`;
}

export function patchViaPost(url, data = {}, options = {}) {
    router.post(withMethodOverride(url, 'PATCH'), data, options);
}

export function putViaPost(url, data = {}, options = {}) {
    router.post(withMethodOverride(url, 'PUT'), data, options);
}

export function deleteViaPost(url, options = {}) {
    router.post(withMethodOverride(url, 'DELETE'), {}, options);
}
