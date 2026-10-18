package com.example.test;

import java.util.List;

// Test fixture for inspect_archive: the e2e test checks the class outline built from this file.
public class TestPlugin implements Runnable {
    protected int[] counts;
    List<String> names;

    public TestPlugin() {
    }

    public static boolean check(String permission, long since, double[] weights) {
        return "testplugin.use".equals(permission) && since > 0 && weights.length > 0;
    }

    @Override
    public void run() {
        System.out.println("Enabling TestPlugin v1.0");
    }
}
