<script setup lang="ts">
import { computed } from 'vue';
import { qrcodegenTyped as qrcodegen } from '@/shared/qr/qrcodegen';

const props = withDefaults(
  defineProps<{
    value: string;
    size?: number;
    margin?: number;
    label?: string;
  }>(),
  {
    size: 200,
    margin: 2,
    label: '',
  },
);

const matrix = computed(() =>
  qrcodegen.QrCode.encodeText(props.value, qrcodegen.QrCode.Ecc.MEDIUM),
);

const dimension = computed(() => matrix.value.size + props.margin * 2);

const path = computed(() => {
  const qr = matrix.value;
  const offset = props.margin;
  let result = '';
  for (let y = 0; y < qr.size; y += 1) {
    for (let x = 0; x < qr.size; x += 1) {
      if (qr.getModule(x, y)) result += `M${x + offset} ${y + offset}h1v1h-1z`;
    }
  }
  return result;
});
</script>

<template>
  <svg
    :width="size"
    :height="size"
    :viewBox="`0 0 ${dimension} ${dimension}`"
    role="img"
    :aria-label="label || undefined"
    shape-rendering="crispEdges"
  >
    <title v-if="label">{{ label }}</title>
    <rect
      :width="dimension"
      :height="dimension"
      fill="#fff"
    />
    <path
      :d="path"
      fill="#000"
    />
  </svg>
</template>
