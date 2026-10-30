import { lsJSON, lsSet, lsRemove } from '@/ls';

// The BYOK AI config { provider, key, model }, shared by the Profile page and the op's AI widget so the two can't
// drift. The key lives in this browser (localStorage 'toady-ai'); with cross-device sync on, the account also keeps
// an encrypted copy — and that copy wins on load.
const LS = 'toady-ai';
export const loadAiConfig = (synced) => synced || lsJSON(LS, {}) || {};
export const storeAiConfig = (cfg) => lsSet(LS, JSON.stringify(cfg));
export const forgetAiConfig = () => lsRemove(LS);
export const syncAiConfig = (cfg) => window.axios.put('/profile/ai-config', cfg).catch(() => {});
export const unsyncAiConfig = () => window.axios.delete('/profile/ai-config').catch(() => {});

// The provider's chat models for this key, through our proxy (`url`: the op's or the profile's). Throws an Error
// with a readable message.
export async function listModels(url, provider, key) {
    try {
        return (await window.axios.post(url, { provider, key })).data.models || [];
    } catch (e) {
        throw new Error(e.response?.data?.error || 'Could not load models — check the key.');
    }
}
// keep the chosen model if the provider still offers it, else its newest
export const pickModel = (models, current) => (current && models.includes(current) ? current : models[0] || '');
