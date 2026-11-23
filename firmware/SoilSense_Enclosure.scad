// ==============================================================================
// SoilSense IoT Station — Parametric Weatherproof 3D Printable Enclosure
// Compatible with: OpenSCAD, FreeCAD, Blender, and Cura/PrusaSlicer (via STL export)
// ==============================================================================

$fn = 60; // Smooth curve resolution

// --- 1. PARAMETRIC USER DIMENSIONS (in mm) ---
box_length      = 150.0; // Outer length
box_width       = 100.0; // Outer width
box_depth       = 50.0;  // Outer base depth
wall_thick      = 3.0;   // Shell wall thickness
lid_height      = 12.0;  // Lid top height
corner_radius   = 8.0;   // Rounded corner radius

// Standoff pillars for Breadboard / ESP32 Tray
standoff_h      = 6.0;
standoff_od     = 7.0;
standoff_id     = 2.8;   // M3 self-tapping screw hole

// Cable Gland Holes (at the bottom face)
gland_diameter  = 12.5;  // Standard PG7 cable gland hole

// Ground Stake Dimensions
stake_length    = 150.0; // Length of ground anchor stake
stake_width     = 22.0;
stake_thick     = 5.0;

// --- 2. MODULES ---

// Rounded Box Primitive
module rounded_box(l, w, h, r) {
    hull() {
        translate([-l/2 + r, -w/2 + r, 0]) cylinder(r=r, h=h);
        translate([ l/2 - r, -w/2 + r, 0]) cylinder(r=r, h=h);
        translate([ l/2 - r,  w/2 - r, 0]) cylinder(r=r, h=h);
        translate([-l/2 + r,  w/2 - r, 0]) cylinder(r=r, h=h);
    }
}

// Main Enclosure Base Body
module enclosure_base() {
    difference() {
        // Outer Body
        rounded_box(box_length, box_width, box_depth, corner_radius);
        
        // Inner Hollow Cavity
        translate([0, 0, wall_thick])
            rounded_box(box_length - 2*wall_thick, box_width - 2*wall_thick, box_depth + 1, corner_radius - 1);
        
        // Lid lip recess (recess for O-ring / gasket seal)
        translate([0, 0, box_depth - 3])
            rounded_box(box_length - wall_thick, box_width - wall_thick, 4, corner_radius - 0.5);

        // Bottom Cable Gland Pass-throughs (for probe cables)
        translate([-35, 0, -1]) cylinder(d=gland_diameter, h=wall_thick + 2); // pH Probe
        translate([  0, 0, -1]) cylinder(d=gland_diameter, h=wall_thick + 2); // DS18B20 Temp Probe
        translate([ 35, 0, -1]) cylinder(d=gland_diameter, h=wall_thick + 2); // Capacitive Moisture
    }

    // 4 Corner Screw Mounting Posts
    post_offset_x = box_length/2 - 12;
    post_offset_y = box_width/2 - 12;
    for (dx = [-post_offset_x, post_offset_x]) {
        for (dy = [-post_offset_y, post_offset_y]) {
            translate([dx, dy, wall_thick]) {
                difference() {
                    cylinder(d=standoff_od + 2, h=box_depth - wall_thick);
                    translate([0, 0, box_depth - wall_thick - 14])
                        cylinder(d=standoff_id, h=15);
                }
            }
        }
    }

    // Internal PCB Mounting Standoffs (M3)
    translate([-40, -25, wall_thick]) standoff();
    translate([ 40, -25, wall_thick]) standoff();
    translate([-40,  25, wall_thick]) standoff();
    translate([ 40,  25, wall_thick]) standoff();
}

module standoff() {
    difference() {
        cylinder(d=standoff_od, h=standoff_h);
        translate([0, 0, 1]) cylinder(d=standoff_id, h=standoff_h + 1);
    }
}

// Weatherproof Top Lid
module enclosure_lid() {
    difference() {
        // Lid main block
        rounded_box(box_length, box_width, lid_height, corner_radius);
        
        // Inner seal lip
        translate([0, 0, -1])
            difference() {
                rounded_box(box_length + 2, box_width + 2, lid_height - wall_thick, corner_radius);
                translate([0, 0, -0.5])
                    rounded_box(box_length - wall_thick, box_width - wall_thick, lid_height, corner_radius - 1);
            }

        // 4 Corner Screw Holes
        post_offset_x = box_length/2 - 12;
        post_offset_y = box_width/2 - 12;
        for (dx = [-post_offset_x, post_offset_x]) {
            for (dy = [-post_offset_y, post_offset_y]) {
                translate([dx, dy, -1]) {
                    cylinder(d=standoff_id + 0.5, h=lid_height + 2);
                    // Countersink for M3 screw head
                    translate([0, 0, lid_height - 3])
                        cylinder(d=6.0, h=4);
                }
            }
        }

        // Embossed SoilSense Logo on Top
        translate([0, 0, lid_height - 0.8])
            linear_extrude(height = 1)
                text("SoilSense IoT", size = 8, font = "Liberation Sans:style=Bold", halign = "center", valign = "center");
    }
}

// Ground Anchor Stake
module ground_stake() {
    difference() {
        union() {
            // Mounting flange
            translate([0, 0, 0])
                cube([stake_width + 20, stake_width + 20, 6], center=true);
            
            // Cross-shaped stake blade
            translate([0, 0, -stake_length/2]) {
                cube([stake_width, stake_thick, stake_length], center=true);
                cube([stake_thick, stake_width, stake_length], center=true);
            }
            // Pointed Tip
            translate([0, 0, -stake_length])
                cylinder(r1=0, r2=stake_width/2, h=25, center=false);
        }

        // Screw holes to bolt to base of enclosure
        translate([ 12,  12, -4]) cylinder(d=3.2, h=10);
        translate([-12,  12, -4]) cylinder(d=3.2, h=10);
        translate([ 12, -12, -4]) cylinder(d=3.2, h=10);
        translate([-12, -12, -4]) cylinder(d=3.2, h=10);
    }
}

// --- 3. ASSEMBLY RENDER ---
// To view components individually, comment/uncomment the sections below:

// 1. Base Housing
color([0.2, 0.25, 0.32]) enclosure_base();

// 2. Lid (Offset upward by 35mm to see inside)
translate([0, 0, box_depth + 35])
    color([0.15, 0.55, 0.85, 0.6]) enclosure_lid();

// 3. Ground Stake (Attached beneath base)
translate([0, 0, -3])
    color([0.35, 0.38, 0.42]) ground_stake();
