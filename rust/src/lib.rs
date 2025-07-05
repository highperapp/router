use std::ffi::{CStr, CString};
use std::os::raw::{c_char, c_int};
use std::ptr;
use std::collections::HashMap;
use serde_json;
use anyhow::Result;
use matchit::Router as MatchitRouter;
use ahash::AHashMap;

#[repr(C)]
pub struct RouterResult {
    pub data: *mut c_char,
    pub len: usize,
    pub status: c_int,
}

impl RouterResult {
    fn success(data: String) -> Box<RouterResult> {
        let c_string = CString::new(data).unwrap_or_else(|_| CString::new("").unwrap());
        let len = c_string.as_bytes().len();
        Box::new(RouterResult {
            data: c_string.into_raw(),
            len,
            status: 0,
        })
    }

    fn error(status: c_int) -> Box<RouterResult> {
        Box::new(RouterResult {
            data: ptr::null_mut(),
            len: 0,
            status,
        })
    }
}

pub struct Router {
    routers: AHashMap<String, MatchitRouter<String>>,
    route_cache: AHashMap<String, String>,
    max_cache_size: usize,
}

impl Router {
    fn new() -> Self {
        Self {
            routers: AHashMap::new(),
            route_cache: AHashMap::with_capacity(1000),
            max_cache_size: 1000,
        }
    }

    fn add_route(&mut self, method: &str, path: &str, handler_id: &str) -> Result<()> {
        let router = self.routers.entry(method.to_string()).or_insert_with(MatchitRouter::new);
        router.insert(path, handler_id.to_string())?;
        Ok(())
    }

    fn match_route(&mut self, method: &str, path: &str) -> Option<(String, Vec<(String, String)>)> {
        // Check cache first
        let cache_key = format!("{}:{}", method, path);
        if let Some(cached) = self.route_cache.get(&cache_key) {
            if let Ok(result) = serde_json::from_str::<(String, Vec<(String, String)>)>(cached) {
                return Some(result);
            }
        }

        // Perform actual matching
        if let Some(router) = self.routers.get(method) {
            if let Ok(matched) = router.at(path) {
                let handler_id = matched.value.clone();
                let params: Vec<(String, String)> = matched.params.iter()
                    .map(|(k, v)| (k.to_string(), v.to_string()))
                    .collect();

                let result = (handler_id, params);

                // Cache the result
                if self.route_cache.len() < self.max_cache_size {
                    if let Ok(serialized) = serde_json::to_string(&result) {
                        self.route_cache.insert(cache_key, serialized);
                    }
                }

                return Some(result);
            }
        }

        None
    }

    fn clear_cache(&mut self) {
        self.route_cache.clear();
    }

    fn get_stats(&self) -> RouterStats {
        let mut route_count = 0;
        for _router in self.routers.values() {
            // Note: matchit doesn't expose route count directly
            // This is an approximation
            route_count += 1; // We'll count routers instead
        }

        RouterStats {
            method_count: self.routers.len(),
            route_count,
            cache_size: self.route_cache.len(),
            cache_capacity: self.max_cache_size,
        }
    }
}

#[derive(serde::Serialize)]
struct RouterStats {
    method_count: usize,
    route_count: usize,
    cache_size: usize,
    cache_capacity: usize,
}

static mut ROUTER: Option<Router> = None;
static mut ROUTER_INIT: std::sync::Once = std::sync::Once::new();

fn get_router() -> &'static mut Router {
    unsafe {
        ROUTER_INIT.call_once(|| {
            ROUTER = Some(Router::new());
        });
        ROUTER.as_mut().unwrap()
    }
}

/// Create a new router instance
#[no_mangle]
pub extern "C" fn router_create() -> *mut RouterResult {
    unsafe {
        ROUTER = Some(Router::new());
    }
    Box::into_raw(RouterResult::success("OK".to_string()))
}

/// Add a route to the router
#[no_mangle]
pub extern "C" fn router_add_route(
    method: *const c_char,
    path: *const c_char,
    handler_id: *const c_char,
) -> *mut RouterResult {
    if method.is_null() || path.is_null() || handler_id.is_null() {
        return Box::into_raw(RouterResult::error(-1));
    }

    let method_str = match unsafe { CStr::from_ptr(method) }.to_str() {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-2)),
    };

    let path_str = match unsafe { CStr::from_ptr(path) }.to_str() {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-3)),
    };

    let handler_str = match unsafe { CStr::from_ptr(handler_id) }.to_str() {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-4)),
    };

    let router = get_router();
    match router.add_route(method_str, path_str, handler_str) {
        Ok(_) => Box::into_raw(RouterResult::success("OK".to_string())),
        Err(_) => Box::into_raw(RouterResult::error(-5)),
    }
}

/// Match a route
#[no_mangle]
pub extern "C" fn router_match(
    method: *const c_char,
    path: *const c_char,
) -> *mut RouterResult {
    if method.is_null() || path.is_null() {
        return Box::into_raw(RouterResult::error(-1));
    }

    let method_str = match unsafe { CStr::from_ptr(method) }.to_str() {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-2)),
    };

    let path_str = match unsafe { CStr::from_ptr(path) }.to_str() {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-3)),
    };

    let router = get_router();
    match router.match_route(method_str, path_str) {
        Some((handler_id, params)) => {
            let result = serde_json::json!({
                "handler": handler_id,
                "params": params.into_iter().collect::<HashMap<String, String>>()
            });
            
            match serde_json::to_string(&result) {
                Ok(json) => Box::into_raw(RouterResult::success(json)),
                Err(_) => Box::into_raw(RouterResult::error(-4)),
            }
        }
        None => Box::into_raw(RouterResult::error(-404)), // Not found
    }
}

/// Clear route cache
#[no_mangle]
pub extern "C" fn router_clear_cache() -> *mut RouterResult {
    let router = get_router();
    router.clear_cache();
    Box::into_raw(RouterResult::success("OK".to_string()))
}

/// Get router statistics
#[no_mangle]
pub extern "C" fn router_get_stats() -> *mut RouterResult {
    let router = get_router();
    let stats = router.get_stats();
    
    match serde_json::to_string(&stats) {
        Ok(json) => Box::into_raw(RouterResult::success(json)),
        Err(_) => Box::into_raw(RouterResult::error(-1)),
    }
}

/// Batch add multiple routes
#[no_mangle]
pub extern "C" fn router_batch_add_routes(
    routes_json: *const c_char,
    len: usize,
) -> *mut RouterResult {
    if routes_json.is_null() {
        return Box::into_raw(RouterResult::error(-1));
    }

    let data_slice = unsafe { std::slice::from_raw_parts(routes_json as *const u8, len) };
    let json_str = match std::str::from_utf8(data_slice) {
        Ok(s) => s,
        Err(_) => return Box::into_raw(RouterResult::error(-2)),
    };

    let routes: Vec<serde_json::Value> = match serde_json::from_str(json_str) {
        Ok(routes) => routes,
        Err(_) => return Box::into_raw(RouterResult::error(-3)),
    };

    let router = get_router();
    let mut added_count = 0;
    let total_routes = routes.len();

    for route in routes {
        if let (Some(method), Some(path), Some(handler)) = (
            route["method"].as_str(),
            route["path"].as_str(),
            route["handler"].as_str(),
        ) {
            if router.add_route(method, path, handler).is_ok() {
                added_count += 1;
            }
        }
    }

    let result = serde_json::json!({
        "added": added_count,
        "total": total_routes
    });

    match serde_json::to_string(&result) {
        Ok(json) => Box::into_raw(RouterResult::success(json)),
        Err(_) => Box::into_raw(RouterResult::error(-4)),
    }
}

/// Free RouterResult memory
#[no_mangle]
pub extern "C" fn free_router_result(result: *mut RouterResult) {
    if result.is_null() {
        return;
    }

    unsafe {
        let result = Box::from_raw(result);
        if !result.data.is_null() {
            let _ = CString::from_raw(result.data);
        }
    }
}

/// Get router capabilities
#[no_mangle]
pub extern "C" fn get_router_capabilities() -> c_int {
    // Bit flags: 1=radix_tree, 2=caching, 4=batch_operations, 8=statistics
    1 | 2 | 4 | 8
}