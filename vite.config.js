import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), "");
    let hmrHost = "127.0.0.1";

    try {
        const hostname = new URL(env.APP_URL || "http://127.0.0.1").hostname;

        if (hostname && hostname !== "localhost") {
            hmrHost = hostname;
        }
    } catch {
        // Keep the IPv4 fallback so HMR does not try [::1].
    }

    return {
        plugins: [
            tailwindcss(),
            vue(),
            laravel({
                input: ["resources/js/app.js"],
                refresh: true,
            }),
        ],
        server: {
            host: "0.0.0.0",
            port: 5173,
            strictPort: true,
            cors: true,
            origin: `http://${hmrHost}:5173`,
            hmr: {
                host: hmrHost,
                protocol: "ws",
                port: 5173,
            },
            watch: {
                ignored: ["**/storage/framework/views/**"],
            },
        },
    };
});
