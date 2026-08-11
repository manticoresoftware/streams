package manticore;

import com.sun.management.GcInfo;

import java.lang.management.GarbageCollectorMXBean;
import java.lang.management.ManagementFactory;

public class ManualGarbageCleaner {
    private final Long startedAt = System.currentTimeMillis();
    private Long lastQueueHandled = 0L;

    public void checkIsNeedRunGC() {
        for (GarbageCollectorMXBean gcBean : ManagementFactory.getGarbageCollectorMXBeans()) {
            com.sun.management.GarbageCollectorMXBean sunGcBean = (com.sun.management.GarbageCollectorMXBean) gcBean;
            GcInfo lastInfo = sunGcBean.getLastGcInfo();
            if (lastInfo != null) {
                long lastGCStartTime = lastInfo.getStartTime();
                if (lastGCStartTime < (lastQueueHandled + 5000)) {
                    System.gc();
                }
            }
        }
    }

    public void setLastQueueHandled() {
        lastQueueHandled = System.currentTimeMillis() - startedAt;
    }
}