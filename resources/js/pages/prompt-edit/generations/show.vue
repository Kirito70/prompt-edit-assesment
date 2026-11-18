<script setup lang="ts">
import type {Generation} from "@/types"
import {history} from "@/routes"
import {Head} from "@inertiajs/vue3";
import Heading from "@/components/Heading.vue";
import {onMounted} from "vue";

const props = defineProps<{
  generation: Generation
}>()

defineOptions({
  layout: (pageProps: { generation: Generation }) => ({
    breadcrumbs: [
      {title: "History", href: history()},
      {title: `Generation: ${pageProps.generation.id}`}
    ]
  })
})

onMounted(() => {
  console.log(props.generation)
})
</script>

<template>
  <Head :title="`Generation: ${props.generation.id}`" />

  <div class="flex flex-col gap-6 p-4">
    <Heading
        :title="`Generation: ${props.generation.id}`"
    />
    <div class="flex flex-col lg:flex-row lg:space-x-12">

    </div>
  </div>
</template>

<style scoped>

</style>