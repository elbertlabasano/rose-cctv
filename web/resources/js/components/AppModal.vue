<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
const props=defineProps<{open:boolean;title:string;busy?:boolean;wide?:boolean}>();
const emit=defineEmits<{close:[]}>();
const dialog=ref<HTMLDialogElement|null>(null);
watch(()=>props.open,async open=>{await nextTick();if(open&&!dialog.value?.open)dialog.value?.showModal();if(!open&&dialog.value?.open)dialog.value?.close();});
onMounted(()=>{if(props.open)dialog.value?.showModal();});
onUnmounted(()=>dialog.value?.close());
</script>
<template><dialog ref="dialog" class="rw-modal" :class="{'rw-modal-wide':wide}" :aria-label="title" @cancel="busy?$event.preventDefault():emit('close')"><header class="rw-modal-head"><h2>{{title}}</h2><button type="button" class="rw-icon-button" aria-label="Close dialog" :disabled="busy" @click="emit('close')">×</button></header><div class="rw-modal-body"><slot/></div></dialog></template>
