# Customer widget 0.2.3

Install [the plugin ZIP](../downloads/wordpress/crystaltap-ai-chatbox-0.2.3.zip?raw=true), preserve the existing database/settings and clear website/page caches. The existing Android 0.1.3 app is compatible.

## Welcome message

Open **CrystalTap AI → Chat Widget → Welcome message**. For example:

```text
Welcome, {name}! How can we help you today?
```

`{name}` is replaced with the saved customer name, including after reload and New chat. Text is rendered safely as text. The greeting appears once in the log and polling does not rebuild an unchanged greeting. Blank settings and the former stock greeting fall back to the new template. Customized messages are preserved. Older conversations without a recorded name use “Guest”.

## Agent status

The visitor sees the currently assigned agent’s public display name. The header changes between waiting, assigned/joining, typing and joined/chatting states as server data changes. Transfers update the displayed name. “Online” requires the agent to be set Online with a heartbeat in the last two minutes; stale presence does not claim the agent is online. An agent whose display name is an email is shown as Support team. Staff emails, logins, credentials and private notes are not included in this public identity.

Updates use the configured polling interval and real typing activity. A sleeping phone may keep receiving push alerts while its interactive heartbeat is stale.

## Larger conversation area

New/default desktop dimensions are 480 × 740 pixels, constrained to available screen space. The previous 380 × 580 stock pair upgrades to the new pair. Customized sizes remain configurable. The header’s **Maximize chat** button expands into the available viewport; **Restore chat size** or Escape returns to the normal layout.

Floating chat fills a phone viewport, including compact screens. The composer stays visible while the conversation scrolls. Visual-viewport resize events adjust the window during viewport changes. Embedded widgets use their container width and also offer Maximize. Actual mobile keyboard behavior depends on the browser and still requires phone verification.

External WhatsApp, Messenger and Viber links remain available in a compact row, without the former explanatory disclaimer. More options contains the extended contact form and privacy information after chat starts. Privacy information remains directly available during the required-name form.

## AI disabled

The first customer message enters the human queue without creating an AI-disabled system notice. The visitor widget hides existing system bubbles while AI is disabled, so older Support update notices do not crowd the log. Stored history is retained. Customer messages, human replies, images and state/typing indicators remain visible. AI-enabled error/fallback behavior remains available when AI is turned on.

## Validation and previews

The browser suite checks preserved customer input during slow session loading, configurable greetings across reload/new chat, live agent names, typing, maximize/restore, preserved body scrolling, compact phone composition, safe links/photos, visitor isolation and existing workflows. Backend checks cover real presence, transferred support identity, email masking, legacy/default configuration and silent human handoff. Screenshots below use synthetic local test data.

[Phone chat](images/widget-0.2.3/mobile.png) · [Maximized desktop chat](images/widget-0.2.3/desktop.png)
