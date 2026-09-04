import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AiSettings } from '@/types';

const PROVIDER_LABELS: Record<string, string> = {
    openrouter: 'OpenRouter',
    nvidia_nim: 'NVIDIA NIM',
};

/**
 * P2c — BYO-key AI provider settings. The stored key NEVER round-trips: the
 * server only says whether one exists (has_key). Saving a new key replaces
 * it; "Remove stored key" clears it so analysis falls back to the
 * deployment env defaults (and to deterministic GD when env has none).
 */
export default function UpdateAiSettingsForm({ className = '' }: { className?: string }) {
    const aiSettings = usePage().props.aiSettings as AiSettings | undefined;

    const provider = aiSettings?.ai_provider ?? '';

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        ai_provider: provider,
        ai_api_key: '',
        ai_model: aiSettings?.ai_model ?? '',
        ai_base_url: aiSettings?.ai_base_url ?? '',
        clear_key: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.ai-settings.update'), {
            onSuccess: () => {
                // The key is write-only: blank the field after a successful
                // save so a refreshed page can never re-submit its value.
                setData('ai_api_key', '');
                setData('clear_key', false);
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-zinc-50">
                    AI Provider
                </h2>

                <p className="mt-1 text-sm text-zinc-300">
                    Optionally bring your own OpenAI-compatible API key for
                    photo analysis and agent reasoning. Stored keys are
                    encrypted at rest and never displayed again. Without a
                    key, the deployment's default provider is used.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="ai_provider" value="Provider" />

                    <select
                        id="ai_provider"
                        className="mt-1 block w-full rounded-md border-zinc-700 bg-zinc-900/60 text-zinc-100 shadow-sm focus:border-amber-400/60 focus:ring-amber-400/60 focus:ring-offset-0"
                        value={data.ai_provider}
                        onChange={(e) => setData('ai_provider', e.target.value)}
                    >
                        <option value="">
                            Deployment default (OpenRouter)
                        </option>
                        {Object.keys(aiSettings?.providers ?? {}).map((key) => (
                            <option key={key} value={key}>
                                {PROVIDER_LABELS[key] ?? key}
                            </option>
                        ))}
                    </select>

                    <InputError className="mt-2" message={errors.ai_provider} />
                </div>

                <div>
                    <InputLabel htmlFor="ai_api_key" value="API key" />

                    <TextInput
                        id="ai_api_key"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.ai_api_key}
                        onChange={(e) => setData('ai_api_key', e.target.value)}
                        placeholder={
                            aiSettings?.has_key
                                ? 'Key saved — leave blank to keep it'
                                : 'sk-or-v1-…'
                        }
                        autoComplete="off"
                    />

                    <InputError className="mt-2" message={errors.ai_api_key} />

                    <div className="mt-2 flex items-center gap-2">
                        {aiSettings?.has_key && (
                            <label className="flex items-center gap-2 text-sm text-zinc-300">
                                <input
                                    type="checkbox"
                                    className="rounded border-zinc-700 bg-zinc-900/60 text-amber-400 focus:ring-amber-400/60"
                                    checked={data.clear_key}
                                    onChange={(e) =>
                                        setData('clear_key', e.target.checked)
                                    }
                                />
                                Remove stored key
                            </label>
                        )}
                    </div>
                </div>

                <div>
                    <InputLabel htmlFor="ai_model" value="Model (optional)" />

                    <TextInput
                        id="ai_model"
                        type="text"
                        className="mt-1 block w-full"
                        value={data.ai_model}
                        onChange={(e) => setData('ai_model', e.target.value)}
                        placeholder={
                            data.ai_provider
                                ? (aiSettings?.default_models?.[data.ai_provider] ?? 'google/gemini-2.5-flash')
                                : 'google/gemini-2.5-flash'
                        }
                        autoComplete="off"
                    />

                    <InputError className="mt-2" message={errors.ai_model} />
                </div>

                <div>
                    <InputLabel
                        htmlFor="ai_base_url"
                        value="Base URL override (optional, advanced)"
                    />

                    <TextInput
                        id="ai_base_url"
                        type="url"
                        className="mt-1 block w-full"
                        value={data.ai_base_url}
                        onChange={(e) => setData('ai_base_url', e.target.value)}
                        placeholder={
                            data.ai_provider && aiSettings?.providers?.[data.ai_provider]
                                ? aiSettings.providers[data.ai_provider]
                                : 'https://openrouter.ai/api/v1'
                        }
                        autoComplete="off"
                    />

                    <InputError className="mt-2" message={errors.ai_base_url} />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>
                        Save AI settings
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-zinc-300">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
