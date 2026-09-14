<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import lottie from "lottie-web";
import loaderAnimation from "../../animations/Single Line Web Lottie Animation.json";

const { turnstileSiteKey } = defineProps({
    turnstileSiteKey: {
        type: String,
        default: "",
    },
});

const form = useForm({
    email: "admin@naac.local",
    password: "Admin@123",
    remember: true,
    turnstile_token: "",
});

const logoUrl =
    "/assets/side-nav-logo/Gemini_Generated_Image_7wme0a7wme0a7wme-removebg-preview.png";

const isExiting = ref(false);
const isLoading = ref(false);
const exitDurationMs = 600;
const turnstileContainer = ref(null);
const loaderContainer = ref(null);
const showLoader = computed(() => isLoading.value);

const TURNSTILE_SCRIPT_ID = "turnstile-script";
const TURNSTILE_RENDER_DELAY_MS = 120;
let turnstileWidgetId = null;
let turnstileRenderTimer = null;
let loaderInstance = null;

const loadTurnstileScript = () => {
    if (!turnstileSiteKey || typeof document === "undefined") {
        return;
    }

    if (document.getElementById(TURNSTILE_SCRIPT_ID)) {
        return;
    }

    const script = document.createElement("script");
    script.id = TURNSTILE_SCRIPT_ID;
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
    script.async = true;
    script.defer = true;
    script.addEventListener("load", scheduleTurnstileRender, { once: true });
    document.head.appendChild(script);
};

const renderTurnstile = () => {
    if (!turnstileSiteKey || !turnstileContainer.value) {
        return;
    }

    if (!window.turnstile || typeof window.turnstile.render !== "function") {
        return;
    }

    if (turnstileWidgetId !== null) {
        window.turnstile.remove(turnstileWidgetId);
        turnstileWidgetId = null;
    }

    turnstileWidgetId = window.turnstile.render(turnstileContainer.value, {
        sitekey: turnstileSiteKey,
        callback: handleTurnstileSuccess,
        "error-callback": handleTurnstileError,
        "expired-callback": handleTurnstileExpired,
    });
};

const scheduleTurnstileRender = () => {
    if (turnstileRenderTimer) {
        return;
    }

    const tryRender = () => {
        if (window.turnstile && typeof window.turnstile.render === "function") {
            turnstileRenderTimer = null;
            renderTurnstile();
            return;
        }

        turnstileRenderTimer = window.setTimeout(
            tryRender,
            TURNSTILE_RENDER_DELAY_MS,
        );
    };

    tryRender();
};

const resetTurnstile = () => {
    if (window.turnstile && turnstileWidgetId !== null) {
        window.turnstile.reset(turnstileWidgetId);
    }
    form.turnstile_token = "";
};

const handleTurnstileSuccess = (token) => {
    form.turnstile_token = token;
    form.clearErrors("turnstile_token");
};

const handleTurnstileError = () => {
    form.turnstile_token = "";
};

const handleTurnstileExpired = () => {
    form.turnstile_token = "";
};

onMounted(() => {
    loadTurnstileScript();
    scheduleTurnstileRender();

    if (loaderContainer.value) {
        loaderInstance = lottie.loadAnimation({
            container: loaderContainer.value,
            renderer: "svg",
            loop: true,
            autoplay: false,
            animationData: loaderAnimation,
        });

        if (showLoader.value) {
            loaderInstance.play();
        }
    }
});

onUnmounted(() => {
    if (turnstileRenderTimer) {
        window.clearTimeout(turnstileRenderTimer);
        turnstileRenderTimer = null;
    }

    if (window.turnstile && turnstileWidgetId !== null) {
        window.turnstile.remove(turnstileWidgetId);
        turnstileWidgetId = null;
    }

    if (loaderInstance) {
        loaderInstance.destroy();
        loaderInstance = null;
    }
});

watch(showLoader, (value) => {
    if (!loaderInstance) {
        return;
    }

    if (value) {
        loaderInstance.play();
    } else {
        loaderInstance.stop();
    }
});

const submit = () => {
    if (isExiting.value || form.processing) {
        return;
    }

    if (!turnstileSiteKey) {
        form.setError("turnstile_token", "Security check is not configured.");
        return;
    }

    if (!form.turnstile_token) {
        form.setError("turnstile_token", "Please complete the security check.");
        return;
    }

    isExiting.value = true;

    window.setTimeout(() => {
        isLoading.value = true;
        form.post("/login", {
            onError: () => {
                isExiting.value = false;
                isLoading.value = false;
                resetTurnstile();
            },
            onFinish: () => {
                if (form.hasErrors) {
                    isExiting.value = false;
                    isLoading.value = false;
                    resetTurnstile();
                }
            },
        });
    }, exitDurationMs);
};
</script>

<template>
    <Head title="Welcome" />

    <main class="landing" :class="{ 'landing--exit': isExiting }">
        <section class="landing__left">
            <img
                class="landing__logo"
                :src="logoUrl"
                alt="Naga Alta Agri Corp logo"
            />
            <p class="landing__kicker">Office of Agricultural Operations</p>
            <div class="landing__badge">Agricultural Management System</div>
            <h1 class="landing__title">Naga Alta Agri Corp</h1>
            <p class="landing__tagline">
                Official platform for inventory, sales, merchandise assessment,
                and workforce records.
            </p>
        </section>

        <section class="landing__right">
            <div class="login-card">
                <h2 class="login-card__title">Authorized Access</h2>
                <p class="login-card__subtitle">
                    Sign in to the official records system.
                </p>

                <form class="login-form" @submit.prevent="submit">
                    <label class="form-field">
                        <span class="form-field__label">Email</span>
                        <input
                            v-model="form.email"
                            class="input"
                            type="email"
                            placeholder="admin@naac.local"
                            required
                        />
                        <small v-if="form.errors.email" class="form-error">{{
                            form.errors.email
                        }}</small>
                    </label>

                    <label class="form-field">
                        <span class="form-field__label">Password</span>
                        <input
                            v-model="form.password"
                            class="input"
                            type="password"
                            placeholder="Enter password"
                            required
                        />
                        <small v-if="form.errors.password" class="form-error">{{
                            form.errors.password
                        }}</small>
                    </label>

                    <div class="turnstile-wrapper">
                        <div
                            v-if="turnstileSiteKey"
                            ref="turnstileContainer"
                            class="turnstile-widget"
                        ></div>
                        <small v-else class="form-error"
                            >Security check unavailable.</small
                        >
                        <small
                            v-if="form.errors.turnstile_token"
                            class="form-error"
                            >{{ form.errors.turnstile_token }}</small
                        >
                    </div>

                    <button
                        class="btn btn--primary login-btn"
                        type="submit"
                        :disabled="form.processing || isExiting"
                    >
                        {{ form.processing ? "Verifying..." : "Enter System" }}
                    </button>
                </form>
            </div>
        </section>

        <div
            class="landing__loader"
            :class="{ 'landing__loader--visible': showLoader }"
            :aria-hidden="!showLoader"
        >
            <div
                ref="loaderContainer"
                class="landing__loader-animation"
                role="status"
                aria-live="polite"
                aria-label="Signing you in"
            ></div>
        </div>
    </main>
</template>
