package com.crystaltap.agent;

/** FCM supplies string extras; our own notifications supply integer extras. */
final class AlertRoute {
    static int conversation(Object value) {
        if (!(value instanceof String) && !(value instanceof Integer) && !(value instanceof Long)) return 0;
        String raw=value.toString();
        if (!raw.matches("[0-9]{1,10}")) return 0;
        try { return Math.max(0,Integer.parseInt(raw)); }
        catch (NumberFormatException ignored) { return 0; }
    }
}
