(() => {
  const mobileQuery = window.matchMedia('(max-width: 1100px)');
  const mobileStorageKey = 'dashboard_sidebar_open_mobile';
  const desktopStorageKey = 'dashboard_sidebar_collapsed_desktop';
  const groupsStorageKey = 'dashboard_sidebar_groups_state';
  const groupAnimationTimers = new WeakMap();
  let lastGroupNonClickToggleAt = 0;

  const getShell = () => document.querySelector('.db-shell');

  const isOpen = () => {
    const shell = getShell();
    return !!shell && shell.classList.contains('is-sidebar-open');
  };

  const isDesktopCollapsed = () => {
    const shell = getShell();
    return !!shell && shell.classList.contains('is-sidebar-collapsed');
  };

  const syncToggleState = () => {
    const expanded = mobileQuery.matches
      ? (isOpen() ? 'true' : 'false')
      : (isDesktopCollapsed() ? 'false' : 'true');
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
      toggle.setAttribute('aria-expanded', expanded);
    });
  };

  const closeSidebar = () => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.remove('is-sidebar-open');
    try {
      localStorage.setItem(mobileStorageKey, '0');
    } catch (error) {
      // no-op
    }
    syncToggleState();
  };

  const openSidebar = () => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.add('is-sidebar-open');
    try {
      localStorage.setItem(mobileStorageKey, '1');
    } catch (error) {
      // no-op
    }
    syncToggleState();
  };

  const setDesktopCollapsed = (collapsed, persist = true) => {
    const shell = getShell();
    if (!shell) {
      return;
    }

    shell.classList.toggle('is-sidebar-collapsed', collapsed);
    if (persist) {
      try {
        localStorage.setItem(desktopStorageKey, collapsed ? '1' : '0');
      } catch (error) {
        // no-op
      }
    }

    syncToggleState();
  };

  const toggleSidebar = () => {
    if (mobileQuery.matches) {
      if (isOpen()) {
        closeSidebar();
        return;
      }

      openSidebar();
      return;
    }

    setDesktopCollapsed(!isDesktopCollapsed());
  };

  const handleResponsiveReset = () => {
    if (!mobileQuery.matches) {
      closeSidebar();
    }

    applyPersistedState();
  };

  const applyPersistedState = () => {
    const shell = getShell();
    if (!shell) {
      document.documentElement.classList.remove('db-sidebar-collapsed-initial');
      return;
    }

    if (!mobileQuery.matches) {
      closeSidebar();
      let shouldCollapse = false;
      try {
        shouldCollapse = localStorage.getItem(desktopStorageKey) === '1';
      } catch (error) {
        shouldCollapse = false;
      }

      setDesktopCollapsed(shouldCollapse, false);
      shell.classList.add('sidebar-state-ready');
      document.documentElement.classList.remove('db-sidebar-collapsed-initial');
      return;
    }

    shell.classList.remove('is-sidebar-collapsed');

    let shouldOpen = false;
    try {
      shouldOpen = localStorage.getItem(mobileStorageKey) === '1';
    } catch (error) {
      shouldOpen = false;
    }

    if (shouldOpen) {
      openSidebar();
    } else {
      closeSidebar();
    }

    shell.classList.add('sidebar-state-ready');
    document.documentElement.classList.remove('db-sidebar-collapsed-initial');
  };

  const readGroupsState = () => {
    try {
      const raw = localStorage.getItem(groupsStorageKey);
      return raw ? JSON.parse(raw) : {};
    } catch (error) {
      return {};
    }
  };

  const writeGroupsState = (state) => {
    try {
      localStorage.setItem(groupsStorageKey, JSON.stringify(state));
    } catch (error) {
      // no-op
    }
  };

  const setGroupOpen = (toggle, items, open, animate = false) => {
    const pendingTimer = groupAnimationTimers.get(items);
    if (pendingTimer) {
      window.clearTimeout(pendingTimer);
      groupAnimationTimers.delete(items);
    }

    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

    const prefersReducedMotion =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!animate || prefersReducedMotion) {
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';

      if (open) {
        items.removeAttribute('hidden');
        return;
      }

      items.setAttribute('hidden', 'hidden');
      return;
    }

    items.style.overflow = 'hidden';
    items.style.transition = 'max-height 180ms ease, opacity 180ms ease';

    if (open) {
      items.removeAttribute('hidden');
      items.style.maxHeight = '0px';
      items.style.opacity = '0';

      requestAnimationFrame(() => {
        items.style.maxHeight = `${items.scrollHeight}px`;
        items.style.opacity = '1';
      });

      const timerId = window.setTimeout(() => {
        items.style.maxHeight = '';
        items.style.opacity = '';
        items.style.overflow = '';
        groupAnimationTimers.delete(items);
      }, 200);
      groupAnimationTimers.set(items, timerId);

      return;
    }

    if (items.hasAttribute('hidden')) {
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';
      return;
    }

    items.style.maxHeight = `${items.scrollHeight}px`;
    items.style.opacity = '1';

    requestAnimationFrame(() => {
      items.style.maxHeight = '0px';
      items.style.opacity = '0';
    });

    const timerId = window.setTimeout(() => {
      items.setAttribute('hidden', 'hidden');
      items.style.transition = '';
      items.style.maxHeight = '';
      items.style.opacity = '';
      items.style.overflow = '';
      groupAnimationTimers.delete(items);
    }, 200);
    groupAnimationTimers.set(items, timerId);
  };

  const applyGroupState = () => {
    const state = readGroupsState();
    document.querySelectorAll('[data-menu-group]').forEach((groupBlock, index) => {
      const toggle = groupBlock.querySelector('[data-menu-group-toggle]');
      const items = groupBlock.querySelector('[data-menu-group-items]');
      if (!toggle || !items) {
        return;
      }

      const groupKey = toggle.getAttribute('data-group-key') || `group-${index}`;
      if (Object.prototype.hasOwnProperty.call(state, groupKey)) {
        setGroupOpen(toggle, items, state[groupKey] === '1');
      }
    });
  };

  const resolveEventTargetElement = (event) => {
    const eventTarget = event.target;
    if (eventTarget instanceof Element) {
      return eventTarget;
    }

    if (eventTarget && eventTarget.parentElement instanceof Element) {
      return eventTarget.parentElement;
    }

    return null;
  };

  const toggleGroup = (groupToggle) => {
    const groupBlock = groupToggle.closest('[data-menu-group]');
    const groupItems = groupBlock ? groupBlock.querySelector('[data-menu-group-items]') : null;
    if (!groupItems) {
      return;
    }

    const open = groupToggle.getAttribute('aria-expanded') !== 'true';
    setGroupOpen(groupToggle, groupItems, open, mobileQuery.matches);

    const state = readGroupsState();
    const groupKey = groupToggle.getAttribute('data-group-key');
    if (groupKey) {
      state[groupKey] = open ? '1' : '0';
      writeGroupsState(state);
    }
  };

  const handleGroupToggleEvent = (event) => {
    const targetElement = resolveEventTargetElement(event);
    if (!targetElement) {
      return false;
    }

    const groupToggle = targetElement.closest('[data-menu-group-toggle]');
    if (!groupToggle) {
      return false;
    }

    event.preventDefault();
    event.stopPropagation();
    toggleGroup(groupToggle);
    return true;
  };

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sidebar-toggle]');
    if (toggle) {
      event.preventDefault();
      toggleSidebar();
      return;
    }

    const backdrop = event.target.closest('[data-sidebar-backdrop]');
    if (backdrop) {
      closeSidebar();
      return;
    }

    const targetElement = resolveEventTargetElement(event);
    const groupToggle = targetElement ? targetElement.closest('[data-menu-group-toggle]') : null;
    if (groupToggle && Date.now() - lastGroupNonClickToggleAt < 500) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }

    if (handleGroupToggleEvent(event)) {
      return;
    }

    const menuLink = event.target.closest('.db-menu-item');
    if (menuLink && mobileQuery.matches) {
      closeSidebar();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      closeSidebar();
    }
  });

  if (typeof window.PointerEvent === 'function') {
    document.addEventListener('pointerup', (event) => {
      if (event.pointerType !== 'touch') {
        return;
      }

      if (handleGroupToggleEvent(event)) {
        lastGroupNonClickToggleAt = Date.now();
      }
    });
  } else {
    document.addEventListener('touchend', (event) => {
      if (handleGroupToggleEvent(event)) {
        lastGroupNonClickToggleAt = Date.now();
      }
    }, { passive: false });
  }

  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', handleResponsiveReset);
  } else {
    mobileQuery.addListener(handleResponsiveReset);
  }

  document.body.addEventListener('htmx:afterSwap', () => {
    applyPersistedState();
    applyGroupState();
  });

  applyPersistedState();
  applyGroupState();
})();
