(() => {
  const initializeSubgroupCascades = (root = document) => {
    const forms = root instanceof HTMLFormElement
      ? [root]
      : root?.querySelectorAll?.('form') || [];

    forms.forEach((form) => {
      if (form.dataset.subgroupCascadeBound === 'true') {
        return;
      }

      const groupSelect = form.querySelector('[data-subgroup-group-select]');
      const subgroupSelect = form.querySelector('[data-subgroup-select]');
      if (!(groupSelect instanceof HTMLSelectElement) || !(subgroupSelect instanceof HTMLSelectElement)) {
        return;
      }

      form.dataset.subgroupCascadeBound = 'true';

      const allOptions = Array.from(subgroupSelect.options).map((option) => ({
        value: option.value,
        text: option.text,
        groupSlug: String(option.getAttribute('data-group-slug') || '').toLowerCase(),
        selected: option.selected,
      }));

      const renderPlaceholder = (message) => {
        subgroupSelect.innerHTML = '';

        const option = document.createElement('option');
        option.value = '';
        option.text = message;
        subgroupSelect.appendChild(option);
        subgroupSelect.value = '';
        subgroupSelect.disabled = true;
      };

      const updateSubgroups = () => {
        const selectedGroup = String(groupSelect.value || '').toLowerCase();
        const currentValue = String(subgroupSelect.value || '').toLowerCase();

        if (selectedGroup === '') {
          renderPlaceholder('Selecione um grupo primeiro');
          return;
        }

        const filtered = allOptions.filter((item) => item.groupSlug === selectedGroup);
        if (filtered.length === 0) {
          renderPlaceholder('Nenhum subgrupo disponível para este grupo');
          return;
        }

        subgroupSelect.innerHTML = '';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.text = 'Selecione um subgrupo';
        subgroupSelect.appendChild(defaultOption);

        filtered.forEach((item) => {
          const option = document.createElement('option');
          option.value = item.value;
          option.text = item.text;
          option.setAttribute('data-group-slug', item.groupSlug);
          subgroupSelect.appendChild(option);
        });

        subgroupSelect.disabled = false;

        if (filtered.some((item) => item.value.toLowerCase() === currentValue)) {
          subgroupSelect.value = currentValue;
          return;
        }

        const initiallySelected = filtered.find((item) => item.selected);
        subgroupSelect.value = initiallySelected ? initiallySelected.value : filtered[0].value;
      };

      groupSelect.addEventListener('change', updateSubgroups);
      updateSubgroups();
    });
  };

  document.body.addEventListener('htmx:afterSwap', (event) => {
    initializeSubgroupCascades(event.detail.target);
  });

  initializeSubgroupCascades();
})();
