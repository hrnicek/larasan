<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
import type { AvatarChoice, AvatarPreset } from '@/modules/account/types';

/**
 * A face: one of the shipped illustrations, a picture of your own, or initials.
 *
 * Each choice saves on its own rather than waiting for the profile form's *Save*. Picking a
 * picture is the whole decision, and a grid that needs a second click to mean anything is one
 * somebody leaves believing they changed it.
 */
const props = defineProps<{
    user: { name: string; avatar: string | null };
    current: AvatarChoice;
    presets: AvatarPreset[];
    maxKilobytes: number;
}>();

const picker = ref<HTMLInputElement | null>(null);
const choice = useForm<{ preset: number | null }>({ preset: null });
const upload = useForm<{ avatar: File | null }>({ avatar: null });
const removing = ref(false);

const busy = computed(
    () => choice.processing || upload.processing || removing.value,
);
const maxMegabytes = computed(() => Math.round(props.maxKilobytes / 1024));

function choose(preset: number): void {
    if (busy.value || preset === props.current.preset) {
        return;
    }

    choice.preset = preset;
    choice.put(AvatarController.update.url(), { preserveScroll: true });
}

function send(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // Cleared at once, so choosing the same file again after a refusal is still a change.
    input.value = '';

    if (!file) {
        return;
    }

    upload.avatar = file;
    upload.post(AvatarController.store.url(), {
        preserveScroll: true,
        onFinish: () => upload.reset(),
    });
}

function remove(): void {
    router.delete(AvatarController.destroy.url(), {
        preserveScroll: true,
        onStart: () => {
            removing.value = true;
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <UserAvatar :user="props.user" size="lg" />

            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="picker?.click()"
                    >
                        <Upload class="size-4" />
                        {{
                            props.current.uploaded
                                ? 'Upload a different picture'
                                : 'Upload a picture'
                        }}
                    </Button>

                    <Button
                        v-if="props.user.avatar"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        @click="remove"
                    >
                        Remove
                    </Button>
                </div>

                <p class="text-xs text-muted-foreground">
                    JPEG, PNG or WebP, up to {{ maxMegabytes }} MB.
                </p>

                <input
                    ref="picker"
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="send"
                />

                <InputError :message="upload.errors.avatar" />
            </div>
        </div>

        <fieldset :disabled="busy">
            <legend class="text-sm font-medium">Or pick an illustration</legend>

            <div class="mt-3 grid grid-cols-6 gap-2 sm:grid-cols-9">
                <button
                    v-for="preset in props.presets"
                    :key="preset.id"
                    type="button"
                    class="relative rounded-lg transition-opacity outline-none focus-visible:ring-2 focus-visible:ring-primary-ring disabled:cursor-wait"
                    :class="
                        props.current.preset === preset.id
                            ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                            : 'hover:opacity-80'
                    "
                    :aria-pressed="props.current.preset === preset.id"
                    :aria-label="`Illustration ${preset.id}`"
                    @click="choose(preset.id)"
                >
                    <img
                        :src="preset.url"
                        alt=""
                        loading="lazy"
                        class="aspect-square w-full rounded-lg bg-muted"
                    />

                    <span
                        v-if="props.current.preset === preset.id"
                        class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground ring-2 ring-background"
                    >
                        <Check class="size-3" />
                    </span>
                </button>
            </div>

            <InputError class="mt-2" :message="choice.errors.preset" />
        </fieldset>
    </div>
</template>
