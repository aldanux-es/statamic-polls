import PollsIndex from './pages/PollsIndex.vue';
import PollsShow from './pages/PollsShow.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-polls::Index', PollsIndex);
    Statamic.$inertia.register('statamic-polls::Show', PollsShow);
});
