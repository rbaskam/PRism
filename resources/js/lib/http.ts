/** Read Laravel's XSRF-TOKEN cookie so plain fetch requests pass CSRF protection. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** GET a Laravel endpoint that answers with JSON (not an Inertia response). */
export async function getJson<T>(url: string, failureLabel = 'Request failed'): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

    if (!response.ok) {
        throw new Error(`${failureLabel} (error ${response.status}).`);
    }

    return (await response.json()) as T;
}

/**
 * POST JSON to a Laravel endpoint that answers with JSON (not an Inertia response).
 * Errors are thrown with a message the UI can show as is, prefixed with `failureLabel`.
 */
export function postJson<T>(url: string, data: Record<string, unknown> = {}, failureLabel = 'Request failed'): Promise<T> {
    return sendJson<T>('POST', url, data, failureLabel);
}

/** Like postJson, for PUT, PATCH and DELETE endpoints that answer with JSON. */
export async function sendJson<T>(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    data: Record<string, unknown> = {},
    failureLabel = 'Request failed',
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(data),
    });

    const body = await response.json().catch(() => null);

    if (response.status === 419) {
        throw new Error('Your session expired. Reload the page and try again.');
    }

    if (!response.ok) {
        throw new Error(
            typeof body?.message === 'string' && body.message !== ''
                ? `${failureLabel}: ${body.message}`
                : `${failureLabel} (error ${response.status}).`,
        );
    }

    return body as T;
}
