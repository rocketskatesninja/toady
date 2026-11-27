// One query-string reader for any app URL — the current page's or a notification's: queryParam(page.url, 'view').
export const queryParam = (url, name) => new URLSearchParams((url || '').split('?')[1] || '').get(name);
