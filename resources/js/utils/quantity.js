const FRACTION_LABELS = {
    "0.25": "1/4",
    "0.5": "1/2",
    "0.75": "3/4",
    "0.3333": "1/3",
    "0.6667": "2/3",
};

const roundQuantity = (value) => Math.round(Number(value) * 10000) / 10000;

const stripZeros = (value) => {
    const formatted = roundQuantity(value).toFixed(4).replace(/\.?0+$/, "");

    return formatted === "" ? "0" : formatted;
};

export const parseQuantity = (raw) => {
    if (raw === null || raw === undefined) {
        return null;
    }

    const original = String(raw).trim();

    if (original === "") {
        return null;
    }

    const normalized = original.replace(/[¼½¾⅓⅔]/g, (symbol) => {
        const map = {
            "¼": "1/4",
            "½": "1/2",
            "¾": "3/4",
            "⅓": "1/3",
            "⅔": "2/3",
        };

        return map[symbol] ?? symbol;
    });

    let quantity = null;
    const mixed = normalized.match(/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/);
    const fraction = normalized.match(/^(\d+)\s*\/\s*(\d+)$/);

    if (mixed) {
        const denominator = Number(mixed[3]);

        if (denominator > 0) {
            quantity = Number(mixed[1]) + Number(mixed[2]) / denominator;
        }
    } else if (fraction) {
        const denominator = Number(fraction[2]);

        if (denominator > 0) {
            quantity = Number(fraction[1]) / denominator;
        }
    } else if (Number.isFinite(Number(normalized))) {
        quantity = Number(normalized);
    }

    if (quantity === null || !Number.isFinite(quantity) || quantity < 0) {
        return null;
    }

    return roundQuantity(quantity);
};

export const displayQuantity = (raw) => {
    const quantity = parseQuantity(raw);

    if (quantity === null) {
        return raw === null || raw === undefined || raw === "" ? "0" : String(raw);
    }

    if (quantity === 0) {
        return "0";
    }

    const whole = Math.floor(quantity + 1e-9);
    const fraction = roundQuantity(quantity - whole);
    const fractionLabel = FRACTION_LABELS[stripZeros(fraction)];

    if (fractionLabel) {
        return whole > 0 ? `${whole} ${fractionLabel}` : fractionLabel;
    }

    const exactLabel = FRACTION_LABELS[stripZeros(quantity)];

    return exactLabel ?? stripZeros(quantity);
};
