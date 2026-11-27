import { ref } from 'vue';
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

/** The settings form's state — provider / key / model plus the provider's model list — for the Profile page and the
 *  AI widget. Each keeps its own layout and its own way of saving (Profile: a save button; the widget: as you type). */
export function useAiSettings(synced) {
    const src = loadAiConfig(synced);
    const provider = ref(src.provider || 'anthropic');
    const key = ref(src.key || '');
    const model = ref(src.model || '');
    const models = ref(model.value ? [model.value] : []); // seeded so the saved model shows before the list loads
    const loading = ref(false);
    const error = ref('');
    async function loadModels() {
        error.value = '';
        if (!key.value.trim()) { models.value = []; return; }
        loading.value = true;
        try {
            models.value = await listModels('/ai/models', provider.value, key.value.trim());
            model.value = pickModel(models.value, model.value);
        } catch (e) {
            error.value = e.message;
            models.value = model.value ? [model.value] : [];
        } finally {
            loading.value = false;
        }
    }
    function switchProvider(p) {
        if (provider.value === p) return;
        provider.value = p;
        model.value = '';
        models.value = [];
        return loadModels();
    }
    const config = () => ({ provider: provider.value, key: key.value.trim(), model: model.value });
    return { provider, key, model, models, loading, error, loadModels, switchProvider, config };
}
